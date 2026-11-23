<x-account-layout :operations="true" :title="'Solicitud #'.$inquiry->id">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/inquiries.css') }}">@vite('resources/css/tekora/contracting-journey.css')@endpush
  <div class="inquiry-page contracting-hub">
  <x-contracting-journey :journey="$journey" />
  <div class="inquiry-detail-grid">
    <section class="account-card inquiry-summary" aria-labelledby="inquiry-service-title">
      <div class="inquiry-summary-heading"><span class="account-eyebrow">Servicio solicitado</span><x-inquiry-status :status="$inquiry->status" /></div>
      <h2 class="inquiry-title" id="inquiry-service-title">{{ $inquiry->service_title }}</h2>
      <p class="account-help">Título del servicio al enviar la solicitud.</p>
      <dl class="inquiry-participants"><div><dt>Cliente</dt><dd>{{ $inquiry->client->name }}</dd></div><div><dt>Prestador</dt><dd>{{ $inquiry->service->providerProfile->display_name }}</dd></div></dl>
      <h3>Necesidad del cliente</h3><p class="inquiry-message">{{ $inquiry->message }}</p>
      @if($publicService)<a href="{{ route('catalog.show', $inquiry->service_id) }}">Consultar publicación actual</a>@else<p class="account-note">La publicación ya no está disponible para nuevas solicitudes. Tu seguimiento se conserva.</p>@endif
      <dl class="inquiry-dates"><div><dt>Enviada</dt><dd><x-inquiry-time :at="$inquiry->created_at" /></dd></div><div><dt>Actualizada</dt><dd><x-inquiry-time :at="$inquiry->updated_at" /></dd></div></dl>
      @if($inquiry->status === \App\Enums\InquiryStatus::Completed)<p class="account-note">Finalizada es un estado operativo. No confirma pago, garantía, satisfacción del cliente ni un contrato formal.</p>@else<p class="account-help">Aceptar una solicitud no crea una reserva, pago ni contrato automático.</p>@endif
    </section>
    <section class="account-card inquiry-timeline" id="historial" aria-labelledby="inquiry-history-title">
      <h2 id="inquiry-history-title">Historial de decisiones</h2><p class="account-help">Horario de Ciudad de México.</p>
      <ol class="tk-ops-timeline">@foreach($inquiry->timeline() as $event)<li><strong>{{ $event['label'] }}</strong><x-inquiry-time :at="$event['at']" /></li>@endforeach</ol>
      @if(in_array($inquiry->status, [\App\Enums\InquiryStatus::Pending, \App\Enums\InquiryStatus::Accepted], true))
        <div class="inquiry-next-step" id="acciones-solicitud" role="group" aria-labelledby="inquiry-next-title"><h3 id="inquiry-next-title">Acciones de la solicitud</h3><x-inquiry-actions :inquiry="$inquiry" /></div>
      @else
        <p class="account-help inquiry-closed-note">Esta solicitud está cerrada. Su historial y conversación se conservan para consulta.</p>
      @endif
    </section>
  </div>
  <x-inquiry-quotes :inquiry="$inquiry" :published-price="$publishedPrice" :quote-rows="$quoteRows" :public-service="$publicService" :journey="$journey" />
  <x-synthetic-provider-payment :summary="$providerCheckout" />
  <x-inquiry-reservations :inquiry="$inquiry" :journey="$journey" :journey-reservation="$journeyReservation" />
  <x-inquiry-conversation :inquiry="$inquiry" :messages="$messages" :read-marker="$readMarker" :unread-messages-count="$unreadMessagesCount" :message-read-token="$messageReadToken" />
  <nav class="inquiry-actions" aria-label="Volver a mis paneles">@can(\App\Enums\Permission::ManageOwnInquiries->value)<a href="{{ route('inquiries.index') }}">Mis solicitudes</a>@endcan @can(\App\Enums\Permission::ManageReceivedInquiries->value)<a href="{{ route('inquiries.received') }}">Solicitudes recibidas</a>@endcan</nav>
  </div>
</x-account-layout>
