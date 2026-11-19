<x-account-layout :operations="true" title="Detalle de cotización">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/pricing.css') }}">@endpush
  @php
    $expired = now('UTC')->toDateTimeImmutable() >= $quote->expiresAt;
    $client = auth('web')->id() === $quote->clientId;
    $open = $inquiry->status === \App\Enums\InquiryStatus::Accepted;
  @endphp
  <a class="catalog-back" href="{{ route('inquiries.show', $inquiry) }}#cotizaciones">← Solicitud #{{ $inquiry->id }}</a>
  <x-ops-heading variant="connect"><span class="account-eyebrow">Acuerdo de importe · Sin operaciones financieras</span><h1>Tu cotización</h1><p>Un importe claro antes de continuar.</p></x-ops-heading>
  <section class="account-card quote-detail">
    <div class="quote-heading"><h2>{{ $inquiry->service_title }}</h2><span class="quote-badge {{ $acceptance ? 'quote-accepted' : ($expired ? 'quote-expired' : 'quote-pending') }}">{{ $acceptance ? 'Cotización aceptada' : ($expired ? 'Cotización caducada' : 'Cotización pendiente') }}</span></div>
    <dl class="quote-participants"><div><dt>Cliente</dt><dd>{{ $inquiry->client->name }}</dd></div><div><dt>Prestador</dt><dd>{{ $inquiry->service->providerProfile->display_name }}</dd></div></dl>
    <div class="quote-total"><span>Importe total · 60 minutos</span><strong>{{ \App\Payments\WebMoney::format($quote->price->amount->totalCents) }}</strong><p>Es el precio publicado, sin comisión adicional para el cliente.</p></div>
    <dl class="quote-breakdown"><div><dt>Comisión Tekora (15 %)</dt><dd>{{ \App\Payments\WebMoney::format($quote->price->amount->commissionCents) }}</dd></div><div><dt>Importe destinado al prestador (complemento)</dt><dd>{{ \App\Payments\WebMoney::format($quote->price->amount->providerCents) }}</dd></div></dl>
    <p class="account-help">Redondeo a centavos: mitad hacia arriba. El procesamiento de pagos futuro será absorbido por Tekora. Estos importes no representan un cobro, saldo, factura ni liquidación.</p>
    <dl class="quote-breakdown"><div><dt>Emitida</dt><dd>{{ \Carbon\CarbonImmutable::instance($quote->issuedAt)->setTimezone('America/Mexico_City')->format('d/m/Y H:i:s') }}</dd></div><div><dt>Válida hasta</dt><dd>{{ \Carbon\CarbonImmutable::instance($quote->expiresAt)->setTimezone('America/Mexico_City')->format('d/m/Y H:i:s') }}</dd></div></dl><p class="account-help">Horario de Ciudad de México. Esta vigencia no retiene un horario.</p>
    @if($acceptance)
      <p class="account-note">Aceptada el {{ \Carbon\CarbonImmutable::instance($acceptance->acceptedAt)->setTimezone('America/Mexico_City')->format('d/m/Y H:i:s') }}. Se conserva este precio aunque la publicación cambie. No se ha creado una reserva ni realizado un pago.</p>
    @elseif($expired)
      <p class="account-note">La vigencia terminó. Puedes volver a la solicitud para consultar una nueva cotización; esta se conserva sin cambios.</p>
    @elseif(!$open || !$publicService)
      <p class="account-note">La solicitud o la publicación ya no admite aceptar esta cotización. El historial se conserva.</p>
    @elseif($client)
      <form class="account-form quote-accept-form" method="POST" action="{{ route('quotes.accept', $quote->id) }}">@csrf @if($journeyReturn ?? false)<input type="hidden" name="_journey" value="1">@endif
        <label class="quote-check"><input type="checkbox" name="acknowledge" value="1" required><span>He revisado y acepto el importe de esta cotización. Entiendo que esto no es un pago ni confirma una reserva.</span></label>
        <button class="account-button" type="submit">Aceptar cotización</button>
      </form>
    @else<p class="account-note">Solo el cliente puede aceptar esta cotización.</p>@endif
    <details class="quote-metadata"><summary>Referencia y política de cálculo</summary><p>Cotización {{ $quote->id }}</p><p>Servicio #{{ $quote->serviceId }} · Cliente #{{ $quote->clientId }} · Perfil prestador #{{ $quote->providerProfileId }}</p><p>Versión de precio {{ $quote->price->version }} · {{ $quote->price->id }}</p><p>{{ $quote->price->amount->policyVersion }} · MXN · 60 minutos</p></details>
  </section>
</x-account-layout>
