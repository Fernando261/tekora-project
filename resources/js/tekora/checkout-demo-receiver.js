const status = document.querySelector('[data-provider-status]');
const list = document.querySelector('[data-pending-list]');
const csrf = document.querySelector('meta[name="checkout-demo-csrf"]').content;
let receiving = false;
const load = async () => {
    if (receiving) return;
    try {
        const response = await fetch('/demo/checkout/provider/pending', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error();
        const { data } = await response.json();
        // Preserve keyboard focus while polling the same set of pending deliveries.
        const fingerprint = JSON.stringify(data.map((item) => [item.id, item.attempt.id]));
        if (list.dataset.fingerprint === fingerprint) return;
        list.dataset.fingerprint = fingerprint; list.replaceChildren();
        status.textContent = data.length ? 'Solo entregas sintéticas pendientes de recepción.' : 'No hay entregas pendientes. Elige efectivo desde la vista del cliente demo.';
        data.forEach((item) => {
            const article = document.createElement('article'); article.className = 'co-receiver-item'; article.dataset.checkoutId = item.id;
            const heading = document.createElement('h3'); heading.textContent = item.service;
            const text = document.createElement('p'); text.textContent = `Ejemplo ${item.id.slice(0, 8)} · ${new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(item.quote.amount.total_cents / 100)} MXN · Entrega en persona · ${item.provider}`;
            const button = document.createElement('button'); button.type = 'button'; button.className = 'co-primary'; button.textContent = 'Representar recepción de efectivo';
            button.addEventListener('click', async () => {
                receiving = true; button.disabled = true;
                try {
                    const result = await fetch(`/demo/checkout/provider/${item.id}/${item.attempt.id}/receive`, { method: 'POST', credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Checkout-Demo-CSRF': csrf }, body: '{}' });
                    const json = await result.json();
                    if (!result.ok || json.data?.attempt?.status !== 'demo_completed' || json.data.attempt.receipt?.demo_only !== true) throw new Error();
                    article.remove(); status.textContent = 'Recepción de demostración registrada por provider_demo. No se acredita dinero real.';
                    list.dataset.fingerprint = '';
                } catch { status.textContent = 'No se acreditó recepción. Consulta de nuevo; la retención puede haber vencido.'; button.disabled = false; }
                finally { receiving = false; }
            });
            article.append(heading, text, button); list.append(article);
        });
    } catch { status.textContent = 'No se pudo consultar el servidor. No se presume una lista vacía ni una recepción.'; }
};
load(); const timer = setInterval(load, 3000); window.addEventListener('pagehide', () => clearInterval(timer));
