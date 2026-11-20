<x-account-layout :operations="true" title="Finalizar contratación">
  @push('styles')
    @vite(['resources/css/tekora/checkout-demo.css', 'resources/css/tekora/checkout-integrated.css'])
  @endpush
  @php
    $remaining = (int) ($context['hold']['remaining_seconds'] ?? 0);
    $canPay = $context['future_payment']['commercial_eligible'] && $remaining > 0;
    $status = $attempt['status'] ?? null;
    $retryable = in_array($status, ['declined', 'temporary_failure'], true);
    $showForm = $canPay && (!$attempt || $retryable);
    $success = in_array($status, ['test_approved', 'cash_received'], true);
    $visualState = $success ? 'success' : ($status === 'awaiting_cash' ? 'awaiting' : ($status === 'declined' ? 'declined' : ($status === 'temporary_failure' ? 'temporary' : 'ready')));
    $method = $attempt['method'] ?? 'card';
  @endphp

  <div class="tk-checkout b32-checkout" data-b32-checkout data-checkout-state="{{ $visualState }}" @if(session('b32_checkout_result')) data-b32-focus-result @endif>
    <a class="co-back-link b32-back" href="{{ route('reservations.show', $reservation) }}">← Volver a mi reserva</a>

    <div class="co-heading b32-heading">
      <div>
        <p class="co-eyebrow">TU PRÓXIMA CONEXIÓN · TEKORA</p>
        <h1>Finaliza tu contratación<span>.</span></h1>
      </div>
      <p class="b32-heading-aside">Un paso más para conectar con lo que necesitas.<small>Checkout integrado · Entorno local de pruebas</small></p>
    </div>

    <section class="co-shell b32-shell" aria-label="Pago de la contratación">
      <header class="co-shell-header">
        <span class="co-shell-title"><span class="co-mini-mark" aria-hidden="true">T</span> CHECKOUT <span> / TU CONTRATACIÓN</span></span>
        <ol class="co-steps" aria-label="Etapas de contratación">
          <li><span aria-hidden="true">✓</span> Cotización</li>
          <li><span aria-hidden="true">✓</span> Horario</li>
          <li aria-current="step"><span>03</span> Pago</li>
        </ol>
      </header>

      <aside class="co-order-bar b32-order" aria-label="Resumen de contratación">
        <div>
          <strong>{{ $context['service']['title'] }}</strong>
          <span>{{ $context['provider']['display_name'] }} · Cotización aceptada</span>
          <span>Comisión Tekora del 15 % incluida, sin cargos adicionales</span>
        </div>
        <div class="b32-order-total">
          <strong>{{ $formattedAmount }}</strong>
          <span>Total acordado</span>
        </div>
        <p class="b32-hold">
          Retención del horario
          <time data-b32-hold data-remaining="{{ $remaining }}">{{ sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60) }}</time>
          <small>Máximo 15 minutos · no se renueva</small>
        </p>
      </aside>

      <div class="co-stage b32-stage" id="b32-payment-stage">
        <div class="co-card-column b32-card-column">
          <div class="co-card-position" data-b32-card-position>
            <div class="co-card-tilt" data-b32-card-tilt>
              <div class="co-card-flip" data-b32-card-flip aria-hidden="true">
                <div class="co-card-face co-card-front">
                  <div class="co-card-surface"></div>
                  <div class="co-mint-surface" data-b32-mint></div>
                  <div class="co-card-grain"></div>
                  <div class="co-card-glint"></div>
                  <div class="co-card-top">
                    <span class="co-chip" aria-hidden="true"><i></i><i></i><i></i></span>
                    <svg class="co-contactless" viewBox="0 0 24 28" fill="none" aria-hidden="true"><path d="M6 10q4 4 0 8m4-12q8 8 0 16m4-20q12 12 0 24"/></svg>
                    <span class="co-card-level">OBSIDIAN <small>TEKORA</small></span>
                  </div>
                  <div class="co-card-number" data-b32-card-number>•••• &nbsp;•••• &nbsp;•••• &nbsp;4242</div>
                  <div class="co-card-bottom">
                    <div><small>TITULAR FICTICIO</small><strong>{{ mb_strtoupper($context['client']['name']) }}</strong></div>
                    <div><small>VENCE</small><strong>12/30</strong></div>
                    <span class="co-card-network">TARJETA<small>SOLO PRUEBAS</small></span>
                  </div>
                  <div class="co-cash-face" data-b32-cash-face @if($method !== 'cash') hidden @endif>
                    <span class="co-eyebrow">TEKORA · ENTREGA DIRECTA</span>
                    <svg viewBox="0 0 120 62" fill="none" aria-hidden="true"><rect x="3" y="3" width="114" height="56" rx="9"/><path d="M18 3c0 9-6 15-15 15m114 0c-9 0-15-6-15-15M3 44c9 0 15 6 15 15m84 0c0-9 6-15 15-15"/><circle cx="60" cy="31" r="17"/><path d="M66 23h-8a5 5 0 0 0 0 10h4a5 5 0 0 1 0 10h-8m6-24v28"/></svg>
                    <strong>EFECTIVO</strong>
                    <small>Recepción por el prestador</small>
                    <span>ENTORNO LOCAL</span>
                  </div>
                </div>
                <div class="co-card-face co-card-back">
                  <div class="co-card-surface"></div>
                  <div class="co-back-stripe"></div>
                  <div class="co-signature"><span>CÓDIGO FICTICIO</span><strong>123</strong></div>
                  <p>Estos datos existen únicamente para probar el recorrido.<br>No introduzcas información bancaria real.</p>
                  <span class="co-back-brand">tekora<span>.</span><small>PRUEBA</small></span>
                </div>
              </div>
            </div>
          </div>
          <p class="co-card-caption b32-card-caption"><span aria-hidden="true">◇</span> Diseño Blue Obsidian · Experiencia Tekora</p>
        </div>

        @if($showForm)
          <section class="co-form b32-form" aria-labelledby="b32-form-title">
            <p class="co-eyebrow">03 / MÉTODO DE PAGO</p>
            <h2 id="b32-form-title">{{ $retryable ? 'Volvamos a intentarlo' : 'Elige cómo pagar' }}</h2>
            <p class="co-form-note">Utiliza únicamente las opciones ficticias de este entorno. No se realizan cargos bancarios.</p>

            @if($retryable)
              <div class="b32-feedback b32-feedback-{{ $status }}" role="status">
                <strong>{{ $status === 'declined' ? 'Tarjeta rechazada' : 'No fue posible procesar la tarjeta' }}</strong>
                <span>Puedes elegir otro resultado de prueba y volver a intentarlo.</span>
                <small>Referencia: {{ $attempt['reference'] }}</small>
              </div>
            @endif

            <form method="POST" action="{{ route('checkout.local.submit', $reservation) }}" autocomplete="off" data-b32-form>
              @csrf
              <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

              <fieldset class="co-methods b32-methods">
                <legend>Elige tu método</legend>
                <label class="b32-method-option">
                  <input type="radio" name="method" value="card" checked data-b32-method>
                  <span class="b32-method-surface"><span class="b32-method-icon" aria-hidden="true">▱</span><strong>Tarjeta</strong><small>Débito o crédito de prueba</small></span>
                </label>
                <label class="b32-method-option">
                  <input type="radio" name="method" value="cash" data-b32-method>
                  <span class="b32-method-surface"><span class="b32-method-icon" aria-hidden="true">◇</span><strong>Efectivo</strong><small>Entrega en persona</small></span>
                </label>
              </fieldset>

              <div data-b32-card>
                <div class="co-field">
                  <label for="b32-number">Tarjeta de prueba</label>
                  <div class="co-select-wrap">
                    <span aria-hidden="true">▱</span>
                    <select name="card_number" id="b32-number" data-b32-number>
                      <option value="4242424242424242">•••• 4242 · Aprobada</option>
                      <option value="4000000000000002">•••• 0002 · Rechazada</option>
                      <option value="4000000000000119">•••• 0119 · Error temporal</option>
                    </select>
                    <span class="co-chevron" aria-hidden="true">⌄</span>
                  </div>
                </div>
                <div class="co-field-row">
                  <div class="co-field">
                    <label for="b32-expiry">Vencimiento ficticio</label>
                    <div class="co-select-wrap b32-fixed-input"><input id="b32-expiry" name="expiry" value="12/30" readonly data-b32-expiry></div>
                  </div>
                  <div class="co-field">
                    <label for="b32-cvv">Código de prueba (CVV)</label>
                    <div class="co-select-wrap b32-fixed-input"><input id="b32-cvv" name="cvv" value="123" readonly data-b32-cvv></div>
                  </div>
                </div>
              </div>

              <div class="co-cash-info b32-cash-info" data-b32-cash-info hidden>
                <strong>Entrega en persona</strong>
                <p>El cliente elige efectivo; el prestador registra su recepción desde su cuenta de Tekora.</p>
                <p>Seleccionarlo no acredita que se haya recibido dinero.</p>
              </div>

              <p class="b32-form-security">Solo tarjetas de prueba predefinidas. No escribas datos bancarios reales.</p>
              @if($errors->any())<p class="b32-feedback b32-feedback-declined" role="alert">Revisa los datos de prueba seleccionados.</p>@endif
              <div class="co-form-total"><span>Importe acordado</span><strong>{{ $formattedAmount }}</strong></div>
              <button class="co-primary b32-primary" type="submit" data-b32-submit>Confirmar pago <span aria-hidden="true">→</span></button>
              <p class="co-local-note"><span aria-hidden="true">◈</span> El servidor valida la retención antes de guardar el resultado.</p>
            </form>
          </section>
        @else
          <section class="co-form b32-result" aria-labelledby="b32-result-title" aria-live="polite">
            <div class="b32-result-symbol b32-result-{{ $visualState }}" aria-hidden="true">
              @if($success) ✓ @elseif($status === 'declined') × @elseif($status === 'temporary_failure') ! @elseif($status === 'awaiting_cash') ◇ @else ◷ @endif
            </div>
            <p class="co-eyebrow">ESTADO DE TU CONTRATACIÓN</p>
            <h2 id="b32-result-title">
              @switch($status)
                @case('test_approved') ¡Pago realizado con éxito! @break
                @case('cash_received') Recepción registrada @break
                @case('declined') Tarjeta rechazada @break
                @case('temporary_failure') No fue posible procesar la tarjeta @break
                @case('awaiting_cash') Efectivo pendiente de recepción @break
                @default Consulta tu reserva @break
              @endswitch
            </h2>
            <p class="b32-result-lead">
              @switch($status)
                @case('test_approved') Tarjeta aprobada · Resultado de prueba registrado. @break
                @case('cash_received') Efectivo recibido por el prestador · Registro de prueba. @break
                @case('awaiting_cash') El prestador podrá registrar la recepción del efectivo desde su cuenta. @break
                @default No se puede registrar otra operación para esta retención. @break
              @endswitch
            </p>
            @if($attempt)
              <section class="co-receipt b32-receipt" aria-label="Comprobante interno de pruebas">
                <span>COMPROBANTE INTERNO · TEKORA</span>
                <strong>{{ $formattedAmount }}</strong>
                <dl>
                  <div><dt>Método</dt><dd>{{ $method === 'cash' ? 'Efectivo' : 'Tarjeta ficticia' }}</dd></div>
                  <div><dt>Referencia</dt><dd>{{ $attempt['reference'] }}</dd></div>
                </dl>
                <small>No es un comprobante bancario ni fiscal. No se realizó un cargo real.</small>
              </section>
            @endif
            <a class="co-secondary b32-secondary" href="{{ route('reservations.show', $reservation) }}">Continuar con mi reserva <span aria-hidden="true">↗</span></a>
          </section>
        @endif
      </div>

      <footer class="co-shell-footer"><span><i aria-hidden="true"></i> ENTORNO LOCAL DE PRUEBAS</span><p>El resultado sintético no sustituye la confirmación definitiva de la reserva.</p></footer>
    </section>

    <section class="co-summary b32-breakdown" aria-label="Desglose del importe acordado">
      <div class="co-service-example">
        <span class="co-service-icon" aria-hidden="true">◇</span>
        <div>
          <p class="co-eyebrow">TU ACUERDO · IMPORTE CONGELADO</p>
          <h2>{{ $context['service']['title'] }}</h2>
          <p>{{ $context['provider']['display_name'] }} · Reserva #{{ $reservation }}</p>
          <span class="co-slot">{{ \Carbon\CarbonImmutable::parse($context['reservation']['starts_at'])->timezone($context['reservation']['timezone'])->format('d/m/Y · H:i') }} · {{ $context['reservation']['timezone'] }}</span>
        </div>
      </div>
      <dl class="co-price-breakdown">
        <div class="co-total"><dt>Total de contratación</dt><dd>{{ $formattedAmount }}</dd></div>
        <div><dt>Para el prestador · 85 %</dt><dd>{{ \App\Payments\WebMoney::format($context['amount']->providerCents) }}</dd></div>
        <div><dt>Comisión Tekora · 15 %</dt><dd>{{ \App\Payments\WebMoney::format($context['amount']->commissionCents) }}</dd></div>
        <p>Comisión incluida en el total. Sin cargos adicionales.</p>
      </dl>
    </section>

    @if(!$canPay)
      <div class="b32-notice" role="status"><span aria-hidden="true">◇</span> La reserva ya no permite registrar un nuevo pago de prueba. Revisa su estado desde «Mi reserva».</div>
    @elseif($attempt && !$retryable)
      <div class="b32-notice b32-notice-active" role="status"><span aria-hidden="true">◇</span> El resultado está guardado. Regresa a la reserva y confírmala antes de que termine la retención.</div>
    @endif
    <p class="b32-legal">Este entorno privado reproduce el recorrido de producto con datos sintéticos. No procesa dinero real, no liquida a prestadores y no genera comprobantes fiscales.</p>
  </div>

  @push('scripts')<script defer src="{{ asset('js/tekora/b32-checkout.js') }}"></script>@endpush
</x-account-layout>
