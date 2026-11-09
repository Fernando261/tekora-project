// Decorative enhancement only. Forms, reading, status changes and navigation stay native.
const root = document.querySelector('.tk-ops');
if (root) {
    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    const fine = matchMedia('(hover: hover) and (pointer: fine)');
    const animations = new Set();
    const pending = new Map();
    const animate = (node, frames, options) => {
        if (motion.matches || !fine.matches || !node.animate) return;
        const animation = node.animate(frames, options);
        animations.add(animation);
        animation.finished.then(() => animations.delete(animation), () => animations.delete(animation));
    };
    document.querySelectorAll('.tk-ops-plane').forEach((node, index) => {
        const transform = getComputedStyle(node).transform;
        animate(node, [{transform: `${transform} translateY(12px) rotateY(-8deg)`}, {transform}], {duration: 640, delay: index * 70, easing: 'cubic-bezier(.22,.68,.2,1)'});
    });
    document.querySelectorAll('.tk-ops-heading-copy').forEach(node => animate(node, [{opacity: .85}, {opacity: 1}], {duration: 600}));
    const cards = [...document.querySelectorAll('[data-ops-card]')];
    if ('IntersectionObserver' in window && !motion.matches && fine.matches) {
        const observer = new IntersectionObserver(entries => {
            entries.filter(entry => entry.isIntersecting).forEach((entry, index) => {
                animate(entry.target, [{opacity: .85}, {opacity: 1}], {duration: 480, delay: Math.min(index, 4) * 75});
                observer.unobserve(entry.target);
            });
        }, {threshold: .1});
        cards.forEach(card => observer.observe(card));
        addEventListener('pagehide', () => observer.disconnect(), {once: true});
    }
    const nodes = [...document.querySelectorAll('[data-ops-scene]'), ...cards];
    const reset = node => {
        if (pending.has(node)) cancelAnimationFrame(pending.get(node));
        pending.delete(node);
        ['--ops-scene-x', '--ops-scene-y', '--ops-card-x', '--ops-card-y'].forEach(name => node.style.removeProperty(name));
    };
    nodes.forEach(node => {
        node.addEventListener('pointermove', event => {
            if (motion.matches || !fine.matches || event.pointerType !== 'mouse') return;
            if (pending.has(node)) cancelAnimationFrame(pending.get(node));
            pending.set(node, requestAnimationFrame(() => {
                const rect = node.getBoundingClientRect();
                const x = Math.max(-1, Math.min(1, (event.clientX - rect.left) / rect.width * 2 - 1));
                const y = Math.max(-1, Math.min(1, (event.clientY - rect.top) / rect.height * 2 - 1));
                const scene = node.hasAttribute('data-ops-scene');
                node.style.setProperty(scene ? '--ops-scene-x' : '--ops-card-x', `${(scene ? 8 : 0) - y * (scene ? 2 : 1)}deg`);
                node.style.setProperty(scene ? '--ops-scene-y' : '--ops-card-y', `${(scene ? -14 : 0) + x * (scene ? 3 : 1.5)}deg`);
                pending.delete(node);
            }));
        });
        node.addEventListener('pointerleave', () => reset(node));
    });
    const stop = () => {
        animations.forEach(animation => animation.cancel());
        animations.clear();
        nodes.forEach(reset);
    };
    motion.addEventListener('change', stop);
    fine.addEventListener('change', stop);
    addEventListener('pagehide', stop, {once: true});
}
