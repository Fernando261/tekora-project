import { animate, createTimeline, cubicBezier, stagger } from 'animejs';

// Sampled from the reference's card translation: slow departure, then a soft settle.
const centerEase = cubicBezier(0.4, 0, 0.2, 1);

// The only state is a choice among these fictional tokens. No banking input,
// form submission, fetch, storage, telemetry, payment SDK or booking command.
const examples = Object.freeze({
    holder: { valeria: 'VALERIA DEMO', alex: 'ALEX DEMO', nexo: 'NEXO ESTUDIO DEMO' },
    number: { debit: { suffix: '0420', type: 'DÉBITO' }, credit: { suffix: '0860', type: 'CRÉDITO' } },
    expiry: { november: '11/30', august: '08/31' },
    code: { masked: '•••', placeholder: 'DEMO' },
    scenario: { success: true, declined: true, temporary: true },
});

const root = document.querySelector('.tk-checkout');
if (root) {
    const find = (name) => root.querySelector(`[data-${name}]`);
    const stage = find('stage');
    const card = find('card-position');
    const tilt = find('card-tilt');
    const flip = find('card-flip');
    const controls = find('controls');
    const fields = find('demo-fields');
    const result = find('result');
    const title = find('result-title');
    const note = find('result-note');
    const spinner = find('spinner');
    const symbol = find('result-symbol');
    const checkPath = find('check-path');
    const actions = find('result-actions');
    const startButton = find('start');
    const resetButton = find('reset');
    const retryButton = find('retry');
    const announcement = find('announcement');
    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    const selects = [...root.querySelectorAll('[data-demo-select]')];
    const animations = new Set();
    const timers = new Set();
    let state = 'ready';
    let generation = 0;
    let pointerAnimation;
    let flipAnimation;
    let resizeFrame;

    const phase = (next) => {
        state = next;
        root.dataset.checkoutState = next;
        root.dispatchEvent(new CustomEvent('tekora:checkout-phase', { detail: { state: next } }));
    };
    const run = (target, options) => {
        const animation = animate(target, { ease: 'out(3)', ...options, duration: motion.matches ? 0 : (options.duration ?? 400) });
        animations.add(animation);
        animation.then(() => animations.delete(animation));
        return animation;
    };
    const stopMotion = () => {
        animations.forEach((animation) => animation.cancel());
        animations.clear();
        pointerAnimation?.cancel();
        flipAnimation?.cancel();
    };
    const later = (callback, milliseconds, token = generation) => {
        const id = setTimeout(() => {
            timers.delete(id);
            if (token === generation) callback();
        }, milliseconds);
        timers.add(id);
    };
    const cancelOperation = () => {
        generation += 1;
        timers.forEach(clearTimeout);
        timers.clear();
        stopMotion();
    };
    const example = (select) => {
        const group = select.dataset.demoSelect;
        const choices = examples[group];
        const key = select.value;
        if (!choices || !Object.hasOwn(choices, key)) {
            select.selectedIndex = 0;
            announcement.textContent = 'Elige uno de los ejemplos ficticios disponibles.';
            return null;
        }
        return choices[key];
    };
    const updateCard = (select) => {
        const value = example(select);
        if (value === null) return;
        const group = select.dataset.demoSelect;
        let target;
        if (group === 'number') {
            target = find('card-number');
            target.textContent = `••••  ••••  ••••  ${value.suffix}`;
            find('card-type').firstChild.textContent = value.type;
        } else if (group !== 'scenario') {
            target = find(`card-${group}`);
            target.textContent = value;
        }
        if (target) run(target, { opacity: [0.45, 1], duration: 230 });
    };
    const showSide = (back) => {
        flipAnimation?.cancel();
        animations.delete(flipAnimation);
        flipAnimation = run(flip, { rotateY: back ? 180 : 0, duration: 280, ease: 'inOut(3)' });
        card.dataset.side = back ? 'back' : 'front';
    };
    const highlight = (group) => {
        const focus = find('card-focus');
        const target = root.querySelector(`[data-card-field="${group}"]`);
        if (!target || group === 'code') {
            run(focus, { opacity: 0, duration: 140 });
            return;
        }
        // Card-local coordinates remain correct while either face is rotating.
        // Holder/expiry are nested in the positioned bottom row, unlike the number.
        const face = root.querySelector('.co-card-front');
        let x = 0;
        let y = 0;
        let element = target;
        while (element && element !== face) {
            x += element.offsetLeft;
            y += element.offsetTop;
            element = element.offsetParent;
        }
        if (element !== face) return;
        run(focus, { x: x - 7, y: y - 5, width: target.offsetWidth + 14, height: target.offsetHeight + 10, opacity: 1, duration: 320 });
    };
    selects.forEach((select) => {
        select.addEventListener('change', () => { if (state === 'ready') updateCard(select); });
        select.addEventListener('focus', () => {
            if (state !== 'ready') return;
            showSide(select.dataset.demoSelect === 'code');
            highlight(select.dataset.demoSelect);
            if (select.dataset.demoSelect === 'code') announcement.textContent = 'Reverso visible. El código es exclusivamente ficticio.';
        });
        select.addEventListener('blur', () => {
            if (state === 'ready' && select.dataset.demoSelect === 'code') showSide(false);
        });
    });
    controls.addEventListener('focusout', (event) => {
        if (state === 'ready' && !controls.contains(event.relatedTarget)) {
            showSide(false);
            highlight('none');
        }
    });
    card.addEventListener('pointermove', (event) => {
        if (state !== 'ready' || motion.matches || event.pointerType !== 'mouse') return;
        const bounds = card.getBoundingClientRect();
        const x = Math.max(-1, Math.min(1, (event.clientX - bounds.left) / bounds.width * 2 - 1));
        const y = Math.max(-1, Math.min(1, (event.clientY - bounds.top) / bounds.height * 2 - 1));
        pointerAnimation?.cancel();
        animations.delete(pointerAnimation);
        pointerAnimation = run(tilt, { rotateX: -y * 4, rotateY: x * 5, duration: 320 });
        find('glint').style.transform = `translateX(${x * 14}px)`;
    });
    card.addEventListener('pointerleave', () => {
        if (state !== 'ready') return;
        pointerAnimation?.cancel();
        animations.delete(pointerAnimation);
        pointerAnimation = run(tilt, { rotateX: 0, rotateY: 0, duration: 600 });
    });

    const centeredGeometry = () => {
        const frame = stage.getBoundingClientRect();
        const bounds = card.getBoundingClientRect();
        const transform = new DOMMatrixReadOnly(getComputedStyle(card).transform);
        const top = innerWidth > 850 ? 64 : 48;
        const height = innerWidth > 850 ? Math.max(560, bounds.height + 295) : bounds.height + 380;
        const recenter = innerWidth > 850 ? (height - frame.height) / 2 : 0;
        return { x: frame.left + frame.width / 2 - (bounds.left - transform.m41 + bounds.width / 2),
            y: frame.top + top - (bounds.top - transform.m42) - recenter,
            height,
            resultTop: top + bounds.height + 28 };
    };
    const positionResult = (instant = false) => {
        const geometry = centeredGeometry();
        result.style.top = `${geometry.resultTop}px`;
        run(stage, { height: geometry.height, duration: instant ? 0 : 700, ease: centerEase });
        run(card, { x: geometry.x, y: geometry.y, duration: instant ? 0 : 700, ease: centerEase });
        return geometry;
    };
    const finish = (outcome) => {
        phase(outcome);
        stage.removeAttribute('aria-busy');
        spinner.hidden = true;
        symbol.removeAttribute('hidden');
        actions.hidden = false;
        retryButton.hidden = outcome !== 'temporary';
        const copy = {
            success: ['Simulación completada', 'No se realizó ningún cobro ni se modificó tu reserva.'],
            declined: ['Simulación rechazada', 'Este rechazo es ficticio. Puedes cambiar el escenario y explorar la experiencia otra vez.'],
            temporary: ['Un momento, vuelve a intentarlo', 'Error temporal de demostración. Reintentar mostrará una recuperación ficticia; no se enviará ningún dato.'],
        }[outcome];
        title.textContent = copy[0];
        note.textContent = copy[1];
        resetButton.firstChild.textContent = outcome === 'success' ? 'Repetir demostración ' : 'Cambiar escenario ';
        checkPath.setAttribute('d', outcome === 'success' ? 'm15 24 6 6 13-14' : outcome === 'declined' ? 'm17 17 14 14m0-14L17 31' : 'M24 14v12m0 7v1');
        run(checkPath, { strokeDashoffset: [38, 0], duration: 380, delay: motion.matches ? 0 : 120 });
        run(symbol, { opacity: [0, 1], scale: [0.82, 1], duration: 400 });
        run([title, note, actions], { opacity: [0, 1], y: [motion.matches ? 0 : 8, 0], duration: 360, delay: motion.matches ? 0 : stagger(70) });
        if (outcome === 'success') {
            run(find('mint'), { opacity: 1, duration: 260, ease: 'inOut(2)' });
            run(find('glint'), { x: [-55, 55], opacity: [0.3, 0.8, 0.25], duration: 720 });
            run(tilt, { rotateX: [0, -3, 0], duration: 580 });
        } else {
            run(tilt, { x: [0, -5, 5, -3, 3, 0], duration: 340, ease: 'inOut(2)' });
        }
        announcement.textContent = `${copy[0]}. ${copy[1]}`;
        title.focus({ preventScroll: true });
    };
    const begin = (outcome, retry = false) => {
        if ((!retry && state !== 'ready') || (retry && state !== 'temporary')) return;
        cancelOperation();
        phase('leaving');
        stage.setAttribute('aria-busy', 'true');
        fields.disabled = true;
        selects.forEach((select) => { select.disabled = true; });
        startButton.disabled = true;
        controls.inert = true;
        result.hidden = false;
        actions.hidden = true;
        symbol.setAttribute('hidden', '');
        spinner.hidden = false;
        title.textContent = 'Preparando la simulación';
        note.textContent = 'Solo una experiencia visual. No se realiza ningún cobro.';
        result.style.opacity = '0';
        result.style.transform = 'translateX(-50%)';
        title.focus({ preventScroll: true });
        showSide(false);
        run(tilt, { x: 0, rotateX: 0, rotateY: 0, duration: 480 });
        run(find('card-focus'), { opacity: 0, duration: 120 });
        run(find('card-caption'), { opacity: 0, duration: 220 });
        if (motion.matches) {
            controls.style.opacity = '0';
        } else {
            const exit = createTimeline({ defaults: { ease: 'out(3)' } })
                .add(controls.querySelectorAll('.co-field'), { opacity: [1, 0], x: [0, 12], duration: 220, delay: stagger(25) }, 0)
                .add(controls, { opacity: [1, 0], filter: ['blur(0px)', 'blur(5px)'], x: [0, 16], duration: 360 }, 40);
            animations.add(exit);
        }
        const geometry = positionResult(motion.matches);
        run(result, { opacity: [0, 1], duration: 260, delay: motion.matches ? 0 : 350 });
        if (innerWidth <= 850) {
            root.querySelector('.co-shell').scrollIntoView({ behavior: motion.matches ? 'instant' : 'smooth', block: 'start' });
        } else {
            // Keep the reference's stationary desktop composition whenever it fits.
            const overflow = stage.getBoundingClientRect().top + geometry.resultTop + 220 - innerHeight + 24;
            if (overflow > 0) window.scrollBy({ top: overflow, behavior: motion.matches ? 'instant' : 'smooth' });
        }
        later(() => {
            controls.style.visibility = 'hidden';
            phase('processing');
            title.textContent = 'Simulando la experiencia';
            announcement.textContent = 'Simulando la experiencia en este navegador. No se realiza ningún cobro.';
        }, motion.matches ? 0 : 660);
        // Reference: form exit/centering ~0–700ms, centered wait, mint at ~2100ms.
        later(() => finish(outcome), motion.matches ? 450 : (retry ? 1500 : 2100));
    };
    const restore = () => {
        if (!['success', 'declined', 'temporary'].includes(state)) return;
        const previous = state;
        cancelOperation();
        phase('restoring');
        run(result, { opacity: 0, duration: 180 });
        run(find('mint'), { opacity: 0, duration: 260 });
        run(tilt, { x: 0, rotateX: 0, rotateY: 0, duration: 360 });
        controls.style.visibility = '';
        controls.style.filter = '';
        controls.style.transform = '';
        controls.querySelectorAll('.co-field').forEach((field) => { field.style.opacity = ''; field.style.transform = ''; });
        stage.style.height = '';
        run(card, { x: 0, y: 0, duration: 560, ease: 'inOut(3)' });
        run(controls, { opacity: [0, 1], duration: 320, delay: motion.matches ? 0 : 180 });
        run(find('card-caption'), { opacity: 1, duration: 300, delay: motion.matches ? 0 : 200 });
        later(() => {
            result.hidden = true;
            // Settle every invariant even if reduced-motion changed mid-transition.
            run(card, { x: 0, y: 0, duration: 0 });
            run(tilt, { x: 0, rotateX: 0, rotateY: 0, duration: 0 });
            controls.style.opacity = '1';
            controls.style.filter = '';
            controls.style.transform = '';
            find('mint').style.opacity = '0';
            find('card-caption').style.opacity = '1';
            controls.inert = false;
            fields.disabled = false;
            selects.forEach((select) => { select.disabled = false; });
            startButton.disabled = false;
            phase('ready');
            announcement.textContent = 'Demostración lista. Tus selecciones ficticias se conservan; no se realizó ningún cobro.';
            (previous === 'success' ? startButton : root.querySelector('#demo-scenario')).focus();
        }, motion.matches ? 0 : 580);
    };
    startButton.addEventListener('click', () => {
        const select = root.querySelector('#demo-scenario');
        if (example(select) !== null) begin(select.value);
    });
    resetButton.addEventListener('click', restore);
    retryButton.addEventListener('click', () => begin('success', true));
    window.addEventListener('resize', () => {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(() => {
            if (['leaving', 'processing', 'success', 'declined', 'temporary'].includes(state)) positionResult(true);
        });
    });
    motion.addEventListener('change', () => {
        stopMotion();
        run(tilt, { x: 0, rotateX: 0, rotateY: 0, duration: 0 });
        if (state === 'ready') {
            showSide(document.activeElement?.dataset.demoSelect === 'code');
        } else if (state !== 'restoring') {
            controls.style.opacity = '0';
            positionResult(true);
            result.style.opacity = '1';
            run(find('mint'), { opacity: state === 'success' ? 1 : 0, duration: 0 });
            if (['success', 'declined', 'temporary'].includes(state)) {
                run([symbol, title, note, actions], { opacity: 1, y: 0, scale: 1, duration: 0 });
                run(checkPath, { strokeDashoffset: 0, duration: 0 });
            }
        }
    });
    window.addEventListener('pagehide', () => { cancelOperation(); cancelAnimationFrame(resizeFrame); });
    symbol.setAttribute('hidden', '');
    fields.disabled = false;
    selects.forEach((select) => { select.disabled = false; });
    startButton.disabled = false;
    card.dataset.side = 'front';
}
