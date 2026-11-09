// One shared clip, measured in the track's coordinates, including horizontal scroll.
export function createDashboardPill(track, tabs, motion) {
    const pill = track.querySelector("[data-dashboard-pill]");
    // Decorative copies share the same clip as the background. Text stays dark wherever
    // the bright pill passes, including the old tab during its elastic departure.
    const labels = tabs.map((tab) => {
        const label = document.createElement("span");
        label.className = "tk-pill-label";
        pill.append(label);
        return label;
    });
    const edges = { l: 0, r: 0, t: 0, b: 0 };
    let trackWidth = track.clientWidth;
    let selected = 0,
        animate,
        tweens = [];
    const stop = () => {
        tweens.forEach((t) => t.cancel());
        tweens = [];
    };
    const measure = (index) => {
        trackWidth = track.clientWidth;
        const outer = track.getBoundingClientRect(),
            rect = tabs[index].getBoundingClientRect();
        tabs.forEach((tab, i) => {
            const bounds = tab.getBoundingClientRect();
            labels[i].innerHTML = tab.innerHTML;
            Object.assign(labels[i].style, {
                left: `${bounds.left - outer.left}px`,
                top: `${bounds.top - outer.top}px`,
                width: `${bounds.width}px`,
                height: `${bounds.height}px`,
            });
        });
        return {
            l: rect.left - outer.left,
            r: outer.right - rect.right,
            t: rect.top - outer.top,
            b: outer.bottom - rect.bottom,
        };
    };
    const paint = () => {
        const l = Math.max(0, Math.min(edges.l, trackWidth - edges.r - 1));
        pill.style.clipPath = `inset(${edges.t}px ${Math.max(0, edges.r)}px ${edges.b}px ${l}px round 10px)`;
    };
    const move = (index, instant = false) => {
        selected = index;
        stop();
        const viewport = track.parentElement.getBoundingClientRect();
        const tab = tabs[index].getBoundingClientRect();
        if (tab.left < viewport.left + 8)
            track.parentElement.scrollLeft -= viewport.left + 8 - tab.left;
        else if (tab.right > viewport.right - 8)
            track.parentElement.scrollLeft += tab.right - viewport.right + 8;
        const target = measure(index),
            forward = target.l - target.r >= edges.l - edges.r;
        if (!animate || motion.matches || instant) {
            Object.assign(edges, target);
            paint();
            return;
        }
        edges.t = target.t;
        edges.b = target.b;
        const [lead, trail] = forward ? ["r", "l"] : ["l", "r"];
        try {
            tweens = [
                animate(edges, {
                    [lead]: target[lead],
                    duration: 360,
                    ease: "out(3)",
                    onUpdate: paint,
                }),
                animate(edges, {
                    [trail]: target[trail],
                    duration: 550,
                    ease: "out(3)",
                    onUpdate: paint,
                }),
            ];
        } catch {
            stop();
            Object.assign(edges, target);
            paint();
        }
    };
    const snap = () => move(selected, true);
    const observer =
        "ResizeObserver" in window ? new ResizeObserver(snap) : null;
    observer?.observe(track);
    tabs.forEach((tab) => observer?.observe(tab));
    window.addEventListener("resize", snap);
    window.visualViewport?.addEventListener("resize", snap);
    document.fonts?.ready.then(snap);
    motion.addEventListener("change", snap);
    move(0, true);
    return {
        move,
        snap,
        useAnimation(fn) {
            animate = fn;
        },
    };
}
