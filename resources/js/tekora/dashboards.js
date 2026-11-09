import { setupDashboardSections } from "./dashboard-sections";

const root = document.querySelector("[data-dashboard]");
const motion = matchMedia("(prefers-reduced-motion: reduce)");
if (root) {
    const sections = setupDashboardSections(root, motion);
    // Loading animation is optional. Tabs and server data work if this chunk fails.
    import("animejs")
        .then(({ animate, stagger }) => {
            sections?.useAnimation(animate);
            root.dataset.dashboardMotion = "ready";
            if (motion.matches) return;
            const cards = [
                ...root.querySelectorAll("[data-dashboard-card]"),
            ].filter((card) => !card.closest("[hidden]"));
            const entries = [];
            if (cards.length)
                entries.push(
                    animate(cards, {
                        opacity: { from: 0.7, to: 1 },
                        y: { from: 6, to: 0 },
                        duration: 320,
                        delay: stagger(35),
                        ease: "out(3)",
                    }),
                );
            const chart = root.querySelector("[data-dashboard-chart]");
            if (chart)
                entries.push(
                    animate(chart, {
                        clipPath: {
                            from: "inset(0 100% 0 0)",
                            to: "inset(0 0% 0 0)",
                        },
                        opacity: { from: 0.7, to: 1 },
                        duration: 550,
                        ease: "out(3)",
                    }),
                );
            motion.addEventListener("change", () => {
                if (motion.matches) entries.forEach((entry) => entry.revert());
            });
        })
        .catch(() => {
            root.dataset.dashboardMotion = "fallback";
        });
}
