import { animate, createTimeline, stagger } from "animejs";

// Only the two explicit GET links are enhanced. Forms and credentials remain browser-managed.
export function enableAuthTransitions(motion) {
    if (
        !document.querySelector("[data-auth-panel]") ||
        !window.fetch ||
        !window.AbortController ||
        !history.pushState
    )
        return;
    let pending = null;
    let renderedPath = location.pathname;

    const clearCard = () => {
        const card = document.querySelector("[data-auth-panel]");
        if (!card) return;
        card.classList.remove("is-flipping");
        card.removeAttribute("aria-busy");
        delete card.dataset.authPhase;
        card.style.removeProperty("transform");
        card.style.removeProperty("height");
        const front = card.querySelector("[data-auth-content]");
        front.inert = false;
        front.style.removeProperty("opacity");
        front.querySelectorAll("[data-auth-entering]").forEach((node) => {
            node.style.removeProperty("opacity");
            node.removeAttribute("data-auth-entering");
        });
        document.querySelector("[data-auth-progress]")?.replaceChildren();
    };
    const cancel = (navigate = false) => {
        const operation = pending;
        if (!operation) return;
        operation.cancelled = true;
        operation.controller.abort();
        try {
            operation.timeline?.cancel();
            operation.heightAnimation?.cancel();
        } finally {
            clearTimeout(operation.fetchTimeout);
            clearTimeout(operation.watchdog);
            pending = null;
            try {
                clearCard();
            } finally {
                if (navigate) location.assign(operation.url.href);
            }
        }
    };
    const focusArrival = (main) => {
        const heading = main.querySelector('[role="alert"], h1');
        if (heading) {
            heading.setAttribute("tabindex", "-1");
            heading.focus({ preventScroll: true });
        }
    };
    const commitURL = (url, title) => {
        history.pushState(null, "", url.href);
        renderedPath = url.pathname;
        document.title = title;
    };

    document.addEventListener("click", async (event) => {
        const link = event.target.closest("a[href]");
        if (!link) return;
        const ordinaryClick =
            event.button === 0 &&
            !event.metaKey &&
            !event.ctrlKey &&
            !event.shiftKey &&
            !event.altKey;
        if (!link.hasAttribute("data-auth-switch")) {
            if (ordinaryClick && !event.defaultPrevented) cancel();
            return;
        }
        const url = new URL(link.href);
        if (
            event.defaultPrevented ||
            !ordinaryClick ||
            link.hasAttribute("download") ||
            (link.target && link.target !== "_self") ||
            motion.matches ||
            document.querySelector('[role="alert"]') ||
            !window.CSS?.supports?.("transform-style", "preserve-3d") ||
            url.origin !== location.origin ||
            !["/login", "/registro"].includes(url.pathname) ||
            url.pathname === location.pathname ||
            url.hash
        )
            return;
        event.preventDefault();
        if (pending) return; // A second activation cannot queue another form or history entry.
        const operation = {
            url,
            controller: new AbortController(),
            cancelled: false,
        };
        pending = operation;
        operation.fetchTimeout = setTimeout(
            () => operation.controller.abort(),
            2500,
        );
        try {
            const response = await fetch(url.href, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                signal: operation.controller.signal,
                headers: { Accept: "text/html" },
            });
            if (operation.cancelled) return;
            if (
                !response.ok ||
                response.url !== url.href ||
                !response.headers.get("Content-Type")?.includes("text/html")
            )
                throw new Error("Normal document navigation required.");
            const parsed = new DOMParser().parseFromString(
                await response.text(),
                "text/html",
            );
            clearTimeout(operation.fetchTimeout);
            if (operation.cancelled) return;
            const source = parsed.querySelector(".tk-page-access main");
            if (
                !source?.querySelector("[data-auth-content]") ||
                source.querySelector("script, iframe, object")
            )
                throw new Error("Unexpected access document.");
            const main = document.importNode(source, true);
            const oldMain = document.querySelector("main");
            // Server validation wins over animation, including errors received during the GET.
            if (main.querySelector('[role="alert"]')) {
                oldMain.replaceWith(main);
                commitURL(url, parsed.title);
                pending = null;
                focusArrival(main);
                return;
            }
            if (motion.matches) {
                cancel(true);
                return;
            }
            const card = oldMain.querySelector("[data-auth-panel]");
            const front = card.querySelector("[data-auth-content]");
            const destinationCard = main.querySelector("[data-auth-panel]");
            const arrival = destinationCard.querySelector(
                "[data-auth-content]",
            );
            const mark = card.querySelector(".tk-auth-back-mark");
            const pulse = card.querySelector(".tk-auth-pulse");
            const halo = card.querySelector(".tk-auth-halo");
            const direction = url.pathname === "/registro" ? 1 : -1;
            const initialHeight = card.offsetHeight;
            const stageHeight = Math.min(
                initialHeight,
                Math.max(300, innerHeight - 160),
            );
            const fields = Array.from(arrival.children).flatMap((node) =>
                node.matches("form") ? Array.from(node.children) : [node],
            );
            arrival.style.opacity = "0";
            arrival.inert = true;
            fields.forEach((node) =>
                node.setAttribute("data-auth-entering", ""),
            );
            const progress = document.querySelector("[data-auth-progress]");
            progress.textContent =
                url.pathname === "/registro"
                    ? "Abriendo registro…"
                    : "Abriendo acceso…";
            progress.focus({ preventScroll: true });
            // Align the destination as a normal document navigation would; no continuous scroll animation.
            scrollTo({
                top: Math.max(
                    0,
                    card.getBoundingClientRect().top + scrollY - 28,
                ),
                behavior: "instant",
            });
            front.inert = true;
            card.style.height = `${initialHeight}px`;
            card.classList.add("is-flipping");
            card.setAttribute("aria-busy", "true");
            card.dataset.authPhase = "exit";
            operation.watchdog = setTimeout(() => cancel(true), 2200);
            const finish = () => {
                if (operation.cancelled) return;
                clearTimeout(operation.watchdog);
                operation.heightAnimation?.complete();
                pending = null;
                clearCard();
                focusArrival(main);
            };
            operation.timeline = createTimeline({
                autoplay: false,
                onComplete: finish,
            })
                .add(
                    front,
                    {
                        opacity: { from: 1, to: 0 },
                        duration: 180,
                        ease: "in(2)",
                    },
                    0,
                )
                .call(() => {
                    card.dataset.authPhase = "turn";
                }, 180)
                .add(
                    card,
                    {
                        rotateY: { from: 0, to: direction * 180 },
                        height: stageHeight,
                        duration: 270,
                        ease: "inOut(2)",
                    },
                    180,
                )
                .call(() => {
                    // Replace the live form while its front faces away. No credential values are copied.
                    front.replaceWith(arrival);
                    destinationCard.replaceWith(card);
                    oldMain.replaceWith(main);
                    commitURL(url, parsed.title);
                    card.dataset.authPhase = "mark";
                }, 450)
                .add(
                    mark,
                    {
                        opacity: { from: 0, to: 1 },
                        scale: { from: 0.8, to: 1 },
                        duration: 200,
                        ease: "out(3)",
                    },
                    450,
                )
                .add(
                    pulse,
                    {
                        opacity: { from: 0, to: 0.8 },
                        scale: { from: 0.6, to: 1.1 },
                        duration: 200,
                        ease: "out(2)",
                    },
                    450,
                )
                .add(
                    halo,
                    {
                        opacity: { from: 0.7, to: 0 },
                        scale: { from: 0.6, to: 1.2 },
                        duration: 300,
                        ease: "out(2)",
                    },
                    450,
                )
                .call(() => {
                    card.dataset.authPhase = "enter";
                    const style = getComputedStyle(card);
                    const height =
                        arrival.offsetHeight +
                        parseFloat(style.paddingTop) +
                        parseFloat(style.paddingBottom) +
                        parseFloat(style.borderTopWidth) +
                        parseFloat(style.borderBottomWidth);
                    // A single bounded height tween accommodates the two real forms without clipping them.
                    operation.heightAnimation = animate(card, {
                        height,
                        duration: 450,
                        ease: "inOut(2)",
                    });
                }, 650)
                .set(arrival, { opacity: 1 }, 650)
                .add(
                    card,
                    {
                        rotateY: { from: direction * 180, to: direction * 360 },
                        duration: 450,
                        ease: "inOut(3)",
                    },
                    650,
                )
                .add(
                    fields,
                    {
                        opacity: { from: 0, to: 1 },
                        delay: stagger(
                            Math.min(14, 100 / Math.max(1, fields.length - 1)),
                        ),
                        duration: 180,
                        ease: "out(2)",
                    },
                    800,
                );
            operation.timeline.play();
        } catch {
            if (!operation.cancelled) cancel(true);
        }
    });
    // These listeners only stop visual work; they never prevent, serialize or replace a POST.
    document.addEventListener("submit", () => cancel());
    document.addEventListener("focusin", (event) => {
        if (pending && event.target.matches("input, select, textarea"))
            cancel();
    });
    window.addEventListener("pagehide", () => cancel());
    window.addEventListener("pageshow", () => {
        clearCard();
        if (location.pathname !== renderedPath) location.reload();
    });
    window.addEventListener("popstate", () => {
        cancel();
        if (location.pathname !== renderedPath) location.reload();
    });
    motion.addEventListener("change", () => {
        if (motion.matches) cancel(true);
    });
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) cancel(true);
    });
    window.addEventListener("resize", () => {
        if (pending?.timeline) cancel(true);
    });
}
