import { animate, stagger } from "animejs";

const root = document.querySelector("[data-dashboard]");
const motion = matchMedia("(prefers-reduced-motion: reduce)");
const running = new Set();
const track = (animation) => {
    running.add(animation);
    animation.then(() => running.delete(animation));
};
if (root && !motion.matches && !root.querySelector('[role="alert"]')) {
    const cards = root.querySelectorAll("[data-dashboard-card]");
    // Values are always the server-rendered values. No animated counters or hidden initial state.
    if (cards.length)
        track(
            animate(cards, {
                opacity: { from: 0.65, to: 1 },
                y: { from: 8, to: 0 },
                duration: 420,
                delay: stagger(45),
                ease: "out(3)",
            }),
        );
    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) continue;
                    observer.unobserve(entry.target);
                    if (!motion.matches)
                        track(
                            animate(entry.target, {
                                opacity: { from: 0.7, to: 1 },
                                y: { from: 8, to: 0 },
                                duration: 400,
                                ease: "out(3)",
                            }),
                        );
                }
            },
            { threshold: 0.08 },
        );
        root.querySelectorAll("[data-dashboard-section]").forEach((node) =>
            observer.observe(node),
        );
    }
    const chart = root.querySelector("[data-dashboard-chart]");
    if (chart)
        track(
            animate(chart, {
                opacity: { from: 0.45, to: 1 },
                duration: 650,
                ease: "out(2)",
            }),
        );
}
motion.addEventListener("change", () => {
    if (motion.matches) {
        running.forEach((animation) => animation.revert());
        running.clear();
    }
});
