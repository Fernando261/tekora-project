import { createDashboardPill } from "./dashboard-pill";

// Progressive local navigation: never fetch, replace routes, or intercept a form.
export function setupDashboardSections(root, motion) {
    const track = root.querySelector("[data-dashboard-track]"),
        panel = root.querySelector("[data-dashboard-panels]");
    if (!track || !panel) return null;
    const anchors = [...track.querySelectorAll("[data-dashboard-tab]")];
    const modules = [
        ...panel.querySelectorAll(":scope > [data-dashboard-module]"),
    ];
    const tabs = anchors.map((anchor) => {
        const button = document.createElement("button");
        button.type = "button";
        button.id = anchor.id;
        button.innerHTML = anchor.innerHTML;
        button.dataset.dashboardTab = anchor.dataset.dashboardTab;
        button.setAttribute("role", "tab");
        button.setAttribute("aria-controls", panel.id);
        return button;
    });
    if (!tabs.length || !modules.length) return null;
    let selected = 0,
        visible = tabs[0].dataset.dashboardTab,
        animate,
        animations = [],
        generation = 0;
    const belongs = (module, key) =>
        module.dataset.dashboardModule.split(" ").includes(key);
    const clean = () => {
        animations.forEach((a) => a.cancel());
        animations = [];
        modules.forEach((m) => {
            m.style.removeProperty("opacity");
            m.style.removeProperty("transform");
        });
    };
    const apply = (key) => {
        for (const module of modules) {
            const hide = !belongs(module, key);
            if (hide && module.contains(document.activeElement))
                tabs[selected].focus({ preventScroll: true });
            module.hidden = hide;
        }
        visible = key;
        root.dataset.dashboardView = key;
        panel.setAttribute("aria-labelledby", tabs[selected].id);
        panel.removeAttribute("aria-busy");
    };
    const finish = () => {
        generation++;
        clean();
        apply(tabs[selected].dataset.dashboardTab);
    };
    let pill;
    const select = async (index, focus = false) => {
        const next = tabs[index].dataset.dashboardTab,
            run = ++generation;
        clean();
        const direction = index >= selected ? 1 : -1;
        selected = index;
        tabs.forEach((tab, i) => {
            tab.setAttribute("aria-selected", String(i === index));
            tab.tabIndex = i === index ? 0 : -1;
        });
        panel.setAttribute("aria-labelledby", tabs[index].id);
        if (focus) tabs[index].focus({ preventScroll: true });
        pill.move(index);
        if (!animate || motion.matches || next === visible) {
            apply(next);
            return;
        }
        const staying = modules.filter((m) => !m.hidden && belongs(m, next));
        const previous = new Map(
            staying.map((m) => [m, m.getBoundingClientRect()]),
        );
        const outgoing = modules.filter((m) => !m.hidden && !belongs(m, next));
        const incoming = modules.filter((m) => m.hidden && belongs(m, next));
        panel.setAttribute("aria-busy", "true");
        try {
            if (outgoing.length) {
                const exit = animate(outgoing, {
                    opacity: { from: 1, to: 0.55 },
                    x: -direction * 10,
                    y: -4,
                    duration: 140,
                    ease: "in(2)",
                });
                animations.push(exit);
                await exit;
            }
            if (run !== generation) return;
            clean();
            apply(next);
            // FLIP translates persistent modules; never scale the chart's data geometry.
            for (const module of staying) {
                const before = previous.get(module),
                    after = module.getBoundingClientRect();
                const seenHeight = Math.max(
                    0,
                    Math.min(innerHeight, before.bottom) -
                        Math.max(0, before.top),
                );
                if (seenHeight < before.height * 0.35) {
                    // A module below the fold has no visible position to preserve. Do not
                    // drag it across an empty stage when it becomes the protagonist.
                    animations.push(
                        animate(module, {
                            opacity: { from: 0.7, to: 1 },
                            x: { from: direction * 12, to: 0 },
                            y: { from: 6, to: 0 },
                            duration: 320,
                            ease: "out(3)",
                        }),
                    );
                    continue;
                }
                animations.push(
                    animate(module, {
                        x: { from: before.left - after.left, to: 0 },
                        y: { from: before.top - after.top, to: 0 },
                        duration: 360,
                        ease: "out(3)",
                    }),
                );
            }
            if (incoming.length)
                animations.push(
                    animate(incoming, {
                        opacity: { from: 0.65, to: 1 },
                        x: { from: direction * 16, to: 0 },
                        y: { from: 6, to: 0 },
                        scale: { from: 0.99, to: 1 },
                        duration: 320,
                        ease: "out(3)",
                    }),
                );
            await Promise.all(animations.map((a) => Promise.resolve(a)));
        } catch {
            if (run === generation) finish();
        } finally {
            if (run === generation) {
                clean();
                apply(next);
            }
        }
    };
    // Build all controls before replacing the fallback anchors or hiding server content.
    anchors.forEach((anchor, i) => anchor.replaceWith(tabs[i]));
    pill = createDashboardPill(track, tabs, motion);
    track.setAttribute("role", "tablist");
    track.setAttribute("aria-label", "Secciones del dashboard");
    panel.setAttribute("role", "tabpanel");
    panel.tabIndex = 0;
    tabs.forEach((tab, index) => {
        tab.setAttribute("aria-selected", String(index === 0));
        tab.tabIndex = index === 0 ? 0 : -1;
        tab.addEventListener("click", () => select(index));
        tab.addEventListener("keydown", (event) => {
            if (event.altKey || event.ctrlKey || event.metaKey) return;
            const targets = {
                ArrowRight: (index + 1) % tabs.length,
                ArrowLeft: (index + tabs.length - 1) % tabs.length,
                Home: 0,
                End: tabs.length - 1,
            };
            if (event.key in targets) {
                event.preventDefault();
                select(targets[event.key], true);
            }
            if (event.key === "Escape") {
                finish();
                pill.snap();
            }
        });
    });
    track.classList.toggle(
        "is-enhanced",
        CSS.supports("clip-path", "inset(0 round 10px)"),
    );
    apply(visible);
    root.dataset.dashboardEnhanced = "true";
    panel.addEventListener("focusin", () => {
        if (panel.getAttribute("aria-busy") === "true") finish();
    });
    motion.addEventListener("change", () => {
        if (motion.matches) finish();
    });
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            finish();
            pill.snap();
        }
    });
    return {
        useAnimation(fn) {
            animate = fn;
            pill.useAnimation(fn);
        },
    };
}
