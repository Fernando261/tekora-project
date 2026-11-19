import { animate, createTimeline, cubicBezier, stagger } from 'animejs';

// Sampled from the reference's card translation: slow departure, then a soft settle.
const centerEase = cubicBezier(0.4, 0, 0.2, 1);

// Only fictional tokens. Results come from the isolated demo server; never a payment SDK or booking command.
const examples = Object.freeze({
    holder: { valeria: 'VALERIA DEMO', alex: 'ALEX DEMO', nexo: 'NEXO ESTUDIO DEMO' },
    number: { debit: { suffix: '0420', type: 'DÉBITO' }, credit: { suffix: '0860', type: 'CRÉDITO' } },
    expiry: { november: '11/30', august: '08/31' },
    code: { masked: '•••', placeholder: 'DEMO' },
    scenario: { success: true, declined: true, temporary: true },
});

const resultCopy = {
    success: ['¡Pago realizado con éxito!', 'Resultado de demostración verificado por el servidor. Ningún cobro externo ni cambio de reserva.'],
    declined: ['No se pudo confirmar', 'Este rechazo es ficticio. Puedes cambiar el escenario y explorar la experiencia otra vez.'],
    temporary: ['Un momento, vuelve a intentarlo', 'El servidor registró un fallo temporal de demostración. Puedes recuperar la misma intención.'],
    awaiting: ['Esperando al prestador', 'Entrega en persona. La recepción debe registrarse desde la vista privada del prestador demo. Elegir efectivo no acredita recepción.'],
    expired: ['La retención de ejemplo venció', 'Los 900 segundos terminaron. Este horario no se renueva; puedes preparar un nuevo ejemplo independiente.'],
    unknown: ['No pudimos consultar el resultado', 'No se acredita ningún éxito. Consulta al servidor antes de volver a intentarlo.'],
};

const root = document.querySelector('.tk-checkout');
if (root) {
    let context = JSON.parse(root.querySelector('[data-demo-bootstrap]').textContent);
    let method = context.attempt?.method ?? 'card';
    let requestPayload;
    let countdownStarted = performance.now();
    const statusMap = { demo_completed: 'success', demo_declined: 'declined', demo_temporary_error: 'temporary', demo_awaiting_cash: 'awaiting', demo_expired: 'expired' };
    const methods = [...root.querySelectorAll('[data-method]')];
    const api = async (path, body) => {
        const response = await fetch(`/demo/checkout/${path}`, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Checkout-Demo-CSRF': context.csrf }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        const json = await response.json();
        if (!response.ok || json.data?.scope !== 'isolated_demo' || json.data.real_payments_enabled !== false) {
            if (response.status === 409 && json.error?.code === 'demo_hold_expired') return { ...context, attempt: { ...context.attempt, status: 'demo_expired', receipt: null } };
            throw new Error('Demo result unavailable');
        }
        return json.data;
    };
    const money = (cents) => `${new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(cents / 100)} MXN`;
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
    let layoutOutcome = 'success';

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

    const mobileResultHeight = () => {
        // Reserve the forthcoming result before centering starts. Measuring an
        // inert, invisible copy avoids a second layout change when text appears.
        const preview = result.cloneNode(true);
        preview.removeAttribute('hidden');
        preview.removeAttribute('aria-labelledby');
        preview.setAttribute('aria-hidden', 'true');
        preview.inert = true;
        preview.style.visibility = 'hidden';
        preview.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
        preview.querySelector('[data-spinner]').hidden = true;
        preview.querySelector('[data-result-symbol]').removeAttribute('hidden');
        preview.querySelector('[data-result-title]').textContent = resultCopy[layoutOutcome][0];
        preview.querySelector('[data-result-note]').textContent = resultCopy[layoutOutcome][1];
        preview.querySelector('[data-result-actions]').hidden = false;
        preview.querySelector('[data-retry]').hidden = !['temporary', 'unknown', 'awaiting'].includes(layoutOutcome);
        preview.querySelector('[data-receipt]').hidden = layoutOutcome !== 'success';
        stage.append(preview);
        const height = Math.ceil(preview.getBoundingClientRect().height);
        preview.remove();
        return height;
    };
    const centeredGeometry = () => {
        const frame = stage.getBoundingClientRect();
        const bounds = card.getBoundingClientRect();
        const transform = new DOMMatrixReadOnly(getComputedStyle(card).transform);
        const top = innerWidth > 850 ? 64 : 48;
        const resultTop = top + bounds.height + (innerWidth > 850 ? 28 : 16);
        const height = Math.max(innerWidth > 850 ? 580 : 0, resultTop + mobileResultHeight() + 28);
        const recenter = innerWidth > 850 ? (height - frame.height) / 2 : 0;
        return { x: frame.left + frame.width / 2 - (bounds.left - transform.m41 + bounds.width / 2),
            y: frame.top + top - (bounds.top - transform.m42) - recenter,
            height,
            resultTop };
    };
    const positionResult = (instant = false) => {
        const geometry = centeredGeometry();
        result.style.top = `${geometry.resultTop}px`;
        run(stage, { height: geometry.height, duration: instant ? 0 : 700, ease: centerEase });
        run(card, { x: geometry.x, y: geometry.y, duration: instant ? 0 : 700, ease: centerEase });
        return geometry;
    };
    const finish = (outcome) => {
        if (outcome === 'success' && (!context.attempt?.receipt || context.attempt.receipt.demo_only !== true || context.attempt.receipt.external_charge !== false)) outcome = 'unknown';
        phase(outcome);
        stage.removeAttribute('aria-busy');
        spinner.hidden = true;
        symbol.removeAttribute('hidden');
        actions.hidden = false;
        retryButton.hidden = !['temporary', 'unknown', 'awaiting'].includes(outcome);
        retryButton.firstChild.textContent = outcome === 'temporary' ? 'Reintentar ' : 'Consultar resultado ';
        find('receipt').hidden = outcome !== 'success';
        if (outcome === 'success') {
            const receipt = context.attempt?.receipt;
            find('receipt-amount').textContent = money(receipt.amount.total_cents);
            find('receipt-method').textContent = receipt.method === 'cash' ? 'Efectivo · entrega en persona' : (context.attempt.instrument === 'credit' ? 'Crédito de prueba' : 'Débito de prueba');
            find('receipt-reference').textContent = receipt.reference;
            find('receipt-date').textContent = new Intl.DateTimeFormat('es-MX', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Mexico_City' }).format(new Date(receipt.recorded_at));
        }
        const copy = resultCopy[outcome];
        title.textContent = copy[0];
        note.textContent = copy[1];
        resetButton.firstChild.textContent = outcome === 'success' || outcome === 'awaiting' ? 'Volver al detalle ' : 'Preparar otro ejemplo ';
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
        layoutOutcome = outcome;
        positionResult();
        if (outcome === 'awaiting') pollReceipt();
    };
    const begin = (operation, retry = false) => {
        if ((!retry && state !== 'ready') || (retry && !['temporary', 'unknown', 'awaiting'].includes(state))) return;
        cancelOperation();
        const operationGeneration = generation;
        layoutOutcome = method === 'cash' ? 'awaiting' : 'success';
        phase('leaving');
        methods.forEach((button) => { button.disabled = true; });
        find('new-demo').disabled = true;
        stage.setAttribute('aria-busy', 'true');
        fields.disabled = true;
        selects.forEach((select) => { select.disabled = true; });
        startButton.disabled = true;
        controls.inert = true;
        result.hidden = false;
        actions.hidden = true;
        symbol.setAttribute('hidden', '');
        spinner.hidden = false;
        title.textContent = 'Preparando la confirmación';
        note.textContent = 'Validando el resultado en el entorno de demostración.';
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
            title.textContent = 'Procesando tu elección';
            announcement.textContent = 'Consultando al servidor demo. No se realiza ningún cobro externo.';
        }, motion.matches ? 0 : 660);
        // Reference: form exit/centering ~0–700ms, centered wait, mint at ~2100ms.
        let settled = false; let serverResult; let minimumElapsed = false;
        const settle = () => {
            if (operationGeneration !== generation || !minimumElapsed || !settled) return;
            find('new-demo').disabled = false;
            if (!serverResult) { finish('unknown'); return; }
            context = serverResult; countdownStarted = performance.now();
            finish(statusMap[context.attempt?.status] ?? 'unknown');
        };
        Promise.resolve().then(operation).then((data) => { serverResult = data; settled = true; settle(); })
            .catch(() => { settled = true; settle(); });
        later(() => { minimumElapsed = true; settle(); }, motion.matches ? 450 : (retry ? 1500 : 2100));
    };
    const restore = () => {
        if (!Object.keys(resultCopy).includes(state)) return;
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
    const chooseMethod = (value) => {
        if (state !== 'ready') return;
        method = value;
        methods.forEach((button) => { button.setAttribute('aria-checked', String(button.dataset.method === value)); button.tabIndex = button.dataset.method === value ? 0 : -1; });
        find('card-options').hidden = value === 'cash';
        find('cash-info').hidden = value !== 'cash';
        find('cash-face').hidden = value !== 'cash';
        root.querySelector('#demo-form-title').textContent = value === 'cash' ? 'Entrega en efectivo' : 'Detalles de la tarjeta';
        showSide(false);
    };
    methods.forEach((button) => {
        button.addEventListener('click', () => chooseMethod(button.dataset.method));
        button.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const index = event.key === 'Home' ? 0 : event.key === 'End' ? 1 : (methods.indexOf(button) + 1) % 2;
            chooseMethod(methods[index].dataset.method); methods[index].focus();
        });
    });
    const pollReceipt = () => later(async () => {
        if (state !== 'awaiting') return;
        const pollingGeneration = generation;
        try {
            const data = await api('context');
            if (pollingGeneration !== generation) return;
            context = data; countdownStarted = performance.now();
            const next = statusMap[context.attempt?.status];
            if (next !== 'awaiting') finish(next ?? 'unknown'); else pollReceipt();
        } catch { if (pollingGeneration === generation) finish('unknown'); }
    }, 2000);
    const refreshExample = async () => {
        if (['leaving', 'processing'].includes(state)) return;
        cancelOperation();
        phase('resetting');
        find('new-demo').disabled = true;
        try { await api('new', {}); location.reload(); }
        catch { find('new-demo').disabled = false; announcement.textContent = 'No se pudo preparar otro ejemplo. El resultado anterior se conserva.'; }
    };
    startButton.addEventListener('click', () => {
        const scenario = root.querySelector('#demo-scenario');
        if (example(scenario) === null) return;
        requestPayload = { method, scenario: scenario.value, idempotency_key: context.id };
        if (method === 'card') requestPayload.instrument = root.querySelector('#demo-card').value;
        begin(() => api('attempts', requestPayload));
    });
    resetButton.addEventListener('click', () => {
        if (state === 'success' || state === 'awaiting') {
            root.querySelector('#demo-detail').scrollIntoView({ behavior: motion.matches ? 'instant' : 'smooth' });
            root.querySelector('#example-summary-title').focus({ preventScroll: true });
        } else refreshExample();
    });
    find('new-demo').addEventListener('click', refreshExample);
    retryButton.addEventListener('click', () => begin(async () => {
        const latest = await api('context');
        if (latest.attempt?.status === 'demo_temporary_error') return api(`attempts/${latest.attempt.id}/retry`, {});
        if (latest.attempt) return latest;
        if (requestPayload) return api('attempts', requestPayload);
        return latest;
    }, true));
    const countdown = setInterval(() => {
        const left = Math.max(0, context.hold.remaining_seconds - Math.floor((performance.now() - countdownStarted) / 1000));
        find('hold').textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
        if (state === 'ready') startButton.disabled = left === 0;
    }, 1000);
    window.addEventListener('pagehide', () => clearInterval(countdown));
    window.addEventListener('resize', () => {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(() => {
            if (['leaving', 'processing', ...Object.keys(resultCopy)].includes(state)) positionResult(true);
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
            if (Object.keys(resultCopy).includes(state)) {
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
    chooseMethod(method);
    if (context.attempt) begin(() => Promise.resolve(context));
}
