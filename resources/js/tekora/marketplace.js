// Visual enhancement only: GET forms, links, permissions and POST remain native.
const root = document.querySelector('[data-marketplace]');
if (root) {
    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    const pointer = matchMedia('(hover: hover) and (pointer: fine)');
    const slow = matchMedia('(update: slow)');
    const animations = new Set();
    const frames = new Map();
    const enabled = () => !motion.matches && pointer.matches && !slow.matches;
    const animate = (node, keyframes, options) => {
        if (!enabled() || !node?.animate) return;
        const animation = node.animate(keyframes, options);
        animations.add(animation);
        animation.finished.catch(() => {}).finally(() => animations.delete(animation));
    };
    const reset = (node) => {
        if (frames.has(node)) cancelAnimationFrame(frames.get(node));
        frames.delete(node);
        ['--mp-rx', '--mp-ry', '--mp-light-x', '--mp-light-y'].forEach(name => node.style.removeProperty(name));
    };
    const stop = () => {
        animations.forEach(animation => animation.cancel());
        animations.clear();
        root.querySelectorAll('[data-market-tilt]').forEach(reset);
    };
    if (enabled()) {
        root.querySelectorAll('[data-market-enter]').forEach((node, index) => {
            animate(node, [{opacity: .85}, {opacity: 1}], {duration: 620, delay: Math.min(index * 75, 225), easing: 'cubic-bezier(.2,.7,.2,1)'});
        });
        root.querySelectorAll('[data-market-scene] [data-market-plane]').forEach(node => {
            animate(node, [{transform: 'rotateX(24deg) rotateY(-32deg) translateZ(-30px)'}, {transform: getComputedStyle(node).transform}], {duration: 720, easing: 'cubic-bezier(.2,.7,.2,1)'});
        });
    }
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(({target, isIntersecting}) => {
                if (!isIntersecting) return;
                observer.unobserve(target);
                // Only the decorative plane moves. Text, links and hit targets stay fixed.
                const plane = target.querySelector('[data-market-plane]');
                animate(plane, [{opacity: .65, transform: 'rotateX(6deg) translateZ(-18px)'}, {opacity: 1, transform: getComputedStyle(plane).transform}], {duration: 520, delay: (Array.from(target.parentElement.children).indexOf(target) % 3) * 70, easing: 'cubic-bezier(.2,.7,.2,1)'});
            });
        }, {threshold: .12});
        root.querySelectorAll('[data-market-card]').forEach(node => observer.observe(node));
    }
    root.querySelectorAll('[data-market-tilt]').forEach(node => {
        node.addEventListener('pointermove', event => {
            if (!enabled() || !CSS.supports('transform-style', 'preserve-3d')) return;
            if (frames.has(node)) cancelAnimationFrame(frames.get(node));
            frames.set(node, requestAnimationFrame(() => {
                const bounds = node.getBoundingClientRect();
                const x = Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width));
                const y = Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height));
                const amount = node.hasAttribute('data-market-card') ? 2 : 4;
                node.style.setProperty('--mp-rx', `${((.5 - y) * amount).toFixed(2)}deg`);
                node.style.setProperty('--mp-ry', `${((x - .5) * amount * 1.5).toFixed(2)}deg`);
                node.style.setProperty('--mp-light-x', `${(x * 100).toFixed(1)}%`);
                node.style.setProperty('--mp-light-y', `${(y * 100).toFixed(1)}%`);
                frames.delete(node);
            }));
        }, {passive: true});
        node.addEventListener('pointerleave', () => reset(node));
        node.addEventListener('focusin', () => reset(node));
    });
    motion.addEventListener('change', () => { if (!enabled()) stop(); });
    pointer.addEventListener('change', () => { if (!enabled()) stop(); });
    slow.addEventListener('change', () => { if (!enabled()) stop(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
    window.addEventListener('pagehide', stop);
}
