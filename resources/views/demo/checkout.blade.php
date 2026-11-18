<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="robots" content="noindex, nofollow">
  <title>Checkout de demostración · Tekora</title>
  @vite(['resources/css/tekora/checkout-demo.css', 'resources/js/tekora/checkout-demo.js'])
</head>
<body class="tk-page tk-checkout" data-checkout-state="ready">
  <div class="co-atmosphere" aria-hidden="true"><i></i><i></i><i></i><span></span></div>
  <a class="tk-skip" href="#checkout-demo">Saltar a la demostración</a>
  <header class="co-site-header co-wrap"><x-tekora-brand /><span class="co-lab-tag"><i aria-hidden="true"></i> LABORATORIO VISUAL <span> / 01</span></span><a class="co-back-link" href="{{ route('tekora.home') }}">Volver a Tekora <span aria-hidden="true"><svg class="co-arrow-icon" viewBox="0 0 24 24" fill="none"><path d="M6 18 18 6M6 6h12v12" /></svg></span></a></header>
  <main id="checkout-demo" class="co-wrap">
    <div class="co-heading"><div><p class="co-eyebrow">TU PRÓXIMA CONEXIÓN</p><h1>El siguiente paso se siente así<span>.</span></h1></div><div class="co-lab-controls"><p class="co-demo-notice"><span aria-hidden="true">◇</span><span><strong>Una demostración. Ningún cobro.</strong><br>Solo ejemplos ficticios; tus reservas no cambian.</span></p><div class="co-field co-scenario-field"><label for="demo-scenario">Resultado de la simulación</label><div class="co-select-wrap"><select id="demo-scenario" autocomplete="off" data-demo-select="scenario" disabled><option value="success">Simulación completada</option><option value="declined">Rechazo de demostración</option><option value="temporary">Error temporal · recuperable al reintentar</option></select><span class="co-chevron" aria-hidden="true">⌄</span></div></div></div></div>
    <section class="co-shell" aria-label="Checkout visual de demostración">
      <header class="co-shell-header"><span class="co-shell-title"><span class="co-mini-mark" aria-hidden="true">T</span> CHECKOUT <span> / VISTA PREVIA</span></span><ol class="co-steps" aria-label="Contexto de ejemplo"><li><span aria-hidden="true">✓</span> Cotización</li><li><span aria-hidden="true">✓</span> Horario</li><li aria-current="step"><span>03</span> Simulación</li></ol></header>
      <div class="co-stage" data-stage>
        <div class="co-card-column">
          <div class="co-card-position" data-card-position>
            <div class="co-card-tilt" data-card-tilt>
              <div class="co-card-flip" data-card-flip aria-hidden="true">
                <div class="co-card-face co-card-front"><div class="co-card-surface"></div><div class="co-mint-surface" data-mint></div><div class="co-card-grain"></div><div class="co-card-glint" data-glint></div>
                  <div class="co-card-top"><span class="co-chip"><i></i><i></i><i></i></span><svg class="co-contactless" viewBox="0 0 24 28" fill="none"><path d="M6 10q4 4 0 8m4-12q8 8 0 16m4-20q12 12 0 24" /></svg><span class="co-card-level">OBSIDIAN <small>DEMO</small></span></div>
                  <div class="co-card-number" data-card-number data-card-field="number">•••• &nbsp;•••• &nbsp;•••• &nbsp;0420</div>
                  <div class="co-card-bottom"><div data-card-field="holder"><small>TITULAR FICTICIO</small><strong data-card-holder>VALERIA DEMO</strong></div><div data-card-field="expiry"><small>VENCE</small><strong data-card-expiry>11/30</strong></div><span class="co-card-network" data-card-type>DÉBITO<small>TARJETA FICTICIA</small></span></div>
                  <span class="co-card-focus" data-card-focus></span>
                </div>
                <div class="co-card-face co-card-back"><div class="co-card-surface"></div><div class="co-back-stripe"></div><div class="co-signature"><span>SOLO DEMOSTRACIÓN</span><strong data-card-code>•••</strong></div><p>Este código es una representación visual.<br>No es una tarjeta bancaria utilizable.</p><span class="co-back-brand">tekora<span>.</span> <small>DEMO</small></span></div>
              </div>
            </div>
          </div>
          <p class="co-card-caption" data-card-caption><span aria-hidden="true">◇</span> Una tarjeta ficticia. Una experiencia real.</p>
        </div>
        <section class="co-form" data-controls role="form" aria-labelledby="demo-form-title" aria-describedby="controlled-data-note">
          <h2 id="demo-form-title">Detalles de la tarjeta</h2>
          <p id="controlled-data-note" class="co-form-note">Solo ejemplos. No escribas datos bancarios.</p>
          <fieldset data-demo-fields disabled><legend class="co-sr-only">Valores ficticios de demostración</legend>
            <div class="co-field"><label for="demo-holder">Titular ficticio</label><div class="co-select-wrap"><span aria-hidden="true">♙</span><select id="demo-holder" autocomplete="off" data-demo-select="holder"><option value="valeria">Valeria Demo</option><option value="alex">Alex Demo</option><option value="nexo">Nexo Estudio Demo</option></select><span class="co-chevron" aria-hidden="true">⌄</span></div></div>
            <div class="co-field"><label for="demo-card">Tarjeta de demostración</label><div class="co-select-wrap"><span aria-hidden="true">▱</span><select id="demo-card" autocomplete="off" data-demo-select="number"><option value="debit">Débito ficticio · •••• 0420</option><option value="credit">Crédito ficticio · •••• 0860</option></select><span class="co-chevron" aria-hidden="true">⌄</span></div></div>
            <div class="co-field-row"><div class="co-field"><label for="demo-expiry">Vencimiento ficticio</label><div class="co-select-wrap"><select id="demo-expiry" autocomplete="off" data-demo-select="expiry"><option value="november">11/30</option><option value="august">08/31</option></select><span class="co-chevron" aria-hidden="true">⌄</span></div></div><div class="co-field"><label for="demo-code">Código demo (CVV)</label><div class="co-select-wrap"><select id="demo-code" autocomplete="off" data-demo-select="code"><option value="masked">••• · Ficticio</option><option value="placeholder">DEMO · Ficticio</option></select><span class="co-chevron" aria-hidden="true">⌄</span></div></div></div>
            <div class="co-form-total"><span>Importe de ejemplo</span><strong>{{ \App\Payments\WebMoney::format($amount->totalCents) }}</strong></div>
            <button type="button" class="co-primary" data-start>Simular confirmación <span aria-hidden="true">→</span></button>
          </fieldset>
          <p class="co-local-note"><span aria-hidden="true">◈</span> Sucede en este navegador. Sin enviar datos.</p>
        </section>
        <section class="co-result" data-result hidden aria-labelledby="demo-result-title"><span class="co-spinner" data-spinner aria-hidden="true"></span><svg class="co-result-symbol" data-result-symbol aria-hidden="true" viewBox="0 0 48 48"><circle cx="24" cy="24" r="20"/><path data-check-path d="m15 24 6 6 13-14"/></svg><h2 id="demo-result-title" tabindex="-1" data-result-title>Preparando la simulación</h2><p data-result-note>Una transición visual. No se realiza ningún cobro.</p><div class="co-result-actions" data-result-actions hidden><button type="button" class="co-primary" data-retry hidden>Reintentar simulación <span aria-hidden="true">→</span></button><button type="button" class="co-secondary" data-reset>Volver a la demostración <span aria-hidden="true"><svg class="co-arrow-icon" viewBox="0 0 24 24" fill="none"><path d="M6 18 18 6M6 6h12v12" /></svg></span></button></div></section>
      </div>
      <footer class="co-shell-footer"><span><i aria-hidden="true"></i> MODO DEMOSTRACIÓN</span><p>Una simulación no confirma una reserva, no extiende una retención y no realiza un cobro.</p></footer>
    </section>
    <section class="co-summary" aria-labelledby="example-summary-title"><div class="co-service-example"><span class="co-service-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="m16 5 7 6-7 6-7-6 7-6Zm-7 9-5 5 12 9 12-9-5-5M4 19l12 1 12-1" /></svg></span><div><p class="co-eyebrow">EJEMPLO FICTICIO · SIN VÍNCULO A UNA RESERVA</p><h2 id="example-summary-title">Una sesión para impulsar tu negocio</h2><p>Nexo Estudio · Prestador de demostración</p><span class="co-slot">{{ $exampleDate }} · 13:00–14:00 · Ciudad de México</span></div></div><dl class="co-price-breakdown"><div class="co-total"><dt>Total de ejemplo</dt><dd>{{ \App\Payments\WebMoney::format($amount->totalCents) }}</dd></div><div><dt>Para el prestador · 85 %</dt><dd>{{ \App\Payments\WebMoney::format($amount->providerCents) }}</dd></div><div><dt>Comisión Tekora · 15 % incluida</dt><dd>{{ \App\Payments\WebMoney::format($amount->commissionCents) }}</dd></div><p>La comisión se desglosa del total; no se añade otro cargo.</p></dl></section>
    <p class="co-stage-announcement co-sr-only" role="status" aria-live="polite" aria-atomic="true" data-announcement>Demostración lista. Solo se pueden seleccionar valores ficticios.</p>
    <noscript><p class="co-noscript">Activa JavaScript para explorar esta demostración animada. Los controles están deshabilitados y no se envía ningún dato.</p></noscript>
    <footer class="co-page-footer"><span>tekora<span>.</span> <small>Conecta con lo que viene.</small></span><span>Blue Obsidian / Experiencia de demostración</span></footer>
  </main>
</body>
</html>
