@props(['inquiry', 'publishedPrice', 'quoteRows', 'publicService', 'journey' => null])
@php
  $fixedReservation = $journey && in_array($journey['reservation']['status'] ?? null, ['confirmed', 'completed'], true);
  $fixedQuote = $fixedReservation && $journey['reservation']['quote_id'] && $journey['reservation']['quote_id'] === ($journey['commercial']['quote']['id'] ?? null)
      && $journey['commercial']['agreed_amount'] ? $journey['commercial']['quote'] : null;
@endphp
@once @push('styles')<link rel="stylesheet" href="{{ asset('css/pricing.css') }}">@endpush @endonce
<section class="account-card quote-section" id="cotizaciones" aria-labelledby="quote-heading">
  <div class="quote-heading"><div><span class="account-eyebrow">Acuerdo de importe</span><h2 id="quote-heading">Cotizaciones</h2></div><span class="quote-badge">Sin cobros</span></div>
  <p class="account-help">Aceptar una cotización conserva el importe. No paga, reserva un horario ni confirma la ejecución del servicio.</p>
  @if($fixedQuote)
    <div class="cj-agreed-price cj-quote-agreement" aria-labelledby="agreed-quote-title">
      <span class="account-eyebrow">Reserva #{{ $journey['reservation']['id'] }} · Acuerdo conservado</span>
      <h3 id="agreed-quote-title">Precio acordado de esta reserva</h3>
      <strong class="cj-agreed-total">{{ \App\Payments\WebMoney::format($fixedQuote['amount']['total_cents']) }}</strong>
      <p>Comisión Tekora del 15 % incluida. Este importe no cambia por nuevas versiones del catálogo ni por otras cotizaciones.</p>
      <a href="{{ route('quotes.show', ['quote' => $fixedQuote['id'], 'follow' => 1]) }}">Consultar cotización vinculada y desglose</a>
    </div>
  @elseif($fixedReservation)
    <p class="account-note">Esta reserva conserva su acuerdo directo, sin cotización comercial vinculada. El precio actual del catálogo y las cotizaciones posteriores no cambian esa reserva.</p>
  @endif
  @if($fixedReservation)
    <details class="cj-other-quotes"><summary>Precio del catálogo y otras cotizaciones</summary>
      <p class="account-note">Las cotizaciones adicionales se consultan por separado. Emitir o aceptar otra cotización no modifica esta reserva ni sustituye su acuerdo.</p>
  @endif
  @if($inquiry->status === \App\Enums\InquiryStatus::Accepted && $publishedPrice && $publicService)
    <div class="quote-current"><span>Precio publicado actual</span><x-published-price :cents="$publishedPrice->amount->totalCents" /><p class="account-help">La cotización se emite con el precio vigente al solicitarla. Revísala antes de aceptar.</p>
    @if(auth('web')->id() === $inquiry->client_user_id && (! $journey || $journey['commercial']['can_issue']))
      <form method="POST" action="{{ route('quotes.issue', $inquiry) }}">@csrf<input type="hidden" name="_journey" value="1"><input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="account-button {{ $fixedReservation ? 'secondary' : '' }}" type="submit">{{ $fixedReservation ? 'Consultar otra cotización por separado' : 'Consultar nueva cotización' }}</button></form>
    @else<p>El cliente puede solicitar y aceptar su cotización desde esta solicitud.</p>@endif</div>
  @elseif(!$publishedPrice)
    <p class="account-note">Precio no publicado. Puedes continuar con la solicitud, los mensajes y las reservas habituales.</p>
  @else<p class="account-help">No se pueden emitir nuevas cotizaciones mientras la solicitud no esté aceptada o la publicación no esté disponible. El historial se conserva.</p>@endif
  @if($fixedReservation)</details>@endif
  <h3 id="quote-history-title">Historial de cotizaciones</h3>
  <ul class="quote-history" aria-labelledby="quote-history-title">
    @forelse($quoteRows as $row)
      @php($expired = now('UTC')->format('Y-m-d H:i:s.u') >= $row->expires_at_utc)
      <li><div><span class="quote-badge {{ $row->accepted_at_utc ? 'quote-accepted' : ($expired ? 'quote-expired' : 'quote-pending') }}">{{ $row->accepted_at_utc ? 'Aceptada' : ($expired ? 'Caducada' : 'Pendiente') }}</span><strong>{{ \App\Payments\WebMoney::format($row->total_cents) }}</strong><span>60 min · Precio v{{ $row->price_version }}</span>@if($fixedQuote && $row->id === $fixedQuote['id'])<span class="quote-badge quote-accepted">Vinculada a esta reserva</span>@elseif($fixedReservation)<span class="account-help">Cotización independiente · No modifica esta reserva</span>@endif</div><a href="{{ route('quotes.show', ['quote' => $row->id, 'follow' => 1]) }}">Ver cotización<span class="sr-only"> emitida el {{ $row->issued_at_utc }} UTC</span> →</a></li>
    @empty<li>Todavía no hay cotizaciones para esta solicitud.</li>@endforelse
  </ul>
  <x-catalog-pagination :paginator="$quoteRows" />
</section>
