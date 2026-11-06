import { animate, stagger } from "animejs";
import { enableAuthTransitions } from "./auth-transition";

// Progressive enhancement: POST and credentials stay with the browser and Laravel.
const motion = window.matchMedia("(prefers-reduced-motion: reduce)");
const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");
const animations = new Set();
const track = (animation) => {
    animations.add(animation);
    animation.then(() => animations.delete(animation));
    return animation;
};
const navigation = document.querySelector("[data-navigation]");
if (navigation) {
    const summary = navigation.querySelector("summary");
    const desktop = window.matchMedia("(min-width: 901px)");
    navigation.open = desktop.matches;
    summary.setAttribute("aria-expanded", String(navigation.open));
    desktop.addEventListener("change", () => {
        navigation.open = desktop.matches;
    });
    navigation.addEventListener("toggle", () =>
        summary.setAttribute("aria-expanded", String(navigation.open)),
    );
    navigation.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && navigation.open) {
            navigation.open = false;
            summary.focus();
        }
    });
    navigation.querySelectorAll("a").forEach((link) =>
        link.addEventListener("click", () => {
            navigation.open = false;
        }),
    );
}

if (!motion.matches) {
    const hero = document.querySelectorAll("[data-hero-enter]");
    if (hero.length)
        track(
            animate(hero, {
                opacity: { from: 0, to: 1 },
                y: { from: 20, to: 0 },
                delay: stagger(85),
                duration: 700,
                ease: "out(4)",
            }),
        );
    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach(({ target, isIntersecting }) => {
                    if (!isIntersecting) return;
                    observer.unobserve(target);
                    if (!motion.matches)
                        track(
                            animate(target, {
                                opacity: { from: 0, to: 1 },
                                y: { from: 16, to: 0 },
                                duration: 520,
                                ease: "out(3)",
                            }),
                        );
                });
            },
            { threshold: 0.12 },
        );
        document
            .querySelectorAll("[data-reveal]")
            .forEach((node) => observer.observe(node));
    }
    document.querySelectorAll(".tk-button, .tk-person").forEach((node) => {
        node.addEventListener("pointerenter", () => {
            if (finePointer.matches && !motion.matches)
                track(animate(node, { y: -2, duration: 180, ease: "out(2)" }));
        });
        node.addEventListener("pointerleave", () => {
            if (!motion.matches)
                track(animate(node, { y: 0, duration: 180, ease: "out(2)" }));
        });
    });
}
const area = document.querySelector("[data-scene-area]");
const scene = document.querySelector("[data-scene]");
let frame = null;
const resetScene = () => {
    if (frame !== null) cancelAnimationFrame(frame);
    scene?.style.removeProperty("--scene-x");
    scene?.style.removeProperty("--scene-y");
};
if (area && scene) {
    area.addEventListener(
        "pointermove",
        (event) => {
            if (motion.matches || !finePointer.matches) return;
            if (frame !== null) cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const bounds = area.getBoundingClientRect();
                scene.style.setProperty(
                    "--scene-x",
                    `${(4 - ((event.clientY - bounds.top) / bounds.height) * 8).toFixed(2)}deg`,
                );
                scene.style.setProperty(
                    "--scene-y",
                    `${(((event.clientX - bounds.left) / bounds.width) * 12 - 6).toFixed(2)}deg`,
                );
            });
        },
        { passive: true },
    );
    area.addEventListener("pointerleave", resetScene);
}
motion.addEventListener("change", () => {
    if (motion.matches) {
        animations.forEach((animation) => animation.revert());
        animations.clear();
        resetScene();
    }
});
finePointer.addEventListener("change", () => {
    if (!finePointer.matches) resetScene();
});

const submitLabels = new WeakMap();
document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!form.matches(".account-form")) return;
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    submitLabels.set(button, button.textContent);
    button.setAttribute("aria-busy", "true");
    button.textContent = "Enviando…";
});
window.addEventListener("pageshow", () => {
    document.querySelectorAll('button[aria-busy="true"]').forEach((button) => {
        button.removeAttribute("aria-busy");
        if (submitLabels.has(button))
            button.textContent = submitLabels.get(button);
    });
});
enableAuthTransitions(motion);
