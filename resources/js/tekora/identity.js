// Decoration only: no form fields, file contents, credentials or network requests.
const motion = matchMedia('(prefers-reduced-motion: reduce)');
const fine = matchMedia('(hover: hover) and (pointer: fine)');
const nodes = [...document.querySelectorAll('.tk-identity [data-identity-scene],.tk-identity [data-identity-card]')];
const animations = [];
const frames = new Map();
if (!motion.matches && fine.matches) document.querySelectorAll('.tk-identity-plane').forEach((node, index) => {
    if (node.animate) animations.push(node.animate([{translate:'0 12px'}, {translate:'0 0'}], {duration:560, delay:index*60, easing:'cubic-bezier(.22,.68,.2,1)'}));
});
const reset = node => {
    cancelAnimationFrame(frames.get(node)); frames.delete(node);
    ['--identity-scene-x','--identity-scene-y','--identity-card-x','--identity-card-y'].forEach(name=>node.style.removeProperty(name));
};
nodes.forEach(node => {
    node.addEventListener('pointermove', event => {
        if (motion.matches || !fine.matches || event.pointerType !== 'mouse') return;
        cancelAnimationFrame(frames.get(node));
        frames.set(node, requestAnimationFrame(() => {
            const r=node.getBoundingClientRect(), x=Math.max(-1,Math.min(1,(event.clientX-r.left)/r.width*2-1)), y=Math.max(-1,Math.min(1,(event.clientY-r.top)/r.height*2-1));
            const scene=node.hasAttribute('data-identity-scene'), prefix=scene?'--identity-scene-':'--identity-card-';
            node.style.setProperty(prefix+'x',`${(scene?7:0)-y*(scene?2:1)}deg`);
            node.style.setProperty(prefix+'y',`${(scene?-12:0)+x*(scene?3:1.5)}deg`);frames.delete(node);
        }));
    });
    node.addEventListener('pointerleave',()=>reset(node));
});
const stop=()=>{animations.forEach(a=>a.cancel());nodes.forEach(reset);};
motion.addEventListener('change',stop);fine.addEventListener('change',stop);addEventListener('pagehide',stop,{once:true});

// Native navigation stays functional without this enhancement. No access-form transition code.
const navigation=document.querySelector('.tk-identity-access [data-navigation]');
if(navigation){
    const summary=navigation.querySelector('summary'), desktop=matchMedia('(min-width:901px)');
    const sync=()=>summary.setAttribute('aria-expanded',String(navigation.open));
    navigation.open=desktop.matches;sync();
    navigation.addEventListener('toggle',sync);
    desktop.addEventListener('change',()=>{navigation.open=desktop.matches;sync();});
    navigation.addEventListener('keydown',e=>{if(e.key==='Escape'&&navigation.open){navigation.open=false;summary.focus();}});
}
