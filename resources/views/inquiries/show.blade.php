<x-account-layout :title="'Solicitud #'.$inquiry->id">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/inquiries.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Seguimiento privado</span><h1>Solicitud #{{ $inquiry->id }}</h1><p>Consulta el estado y las decisiones de esta solicitud.</p></header>
  <div class="inquiry-detail-grid">
    <section class="account-card">
      <x-inquiry-status :status="$inquiry->status" />
      <h2 class="inquiry-title">{{ $inquiry->service_title }}</h2>
      <p class="account-help">Título del servicio al enviar la solicitud.</p>
      <p>Prestador: {{ $inquiry->service->providerProfile->display_name }}</p>
      @can('accept', $inquiry)<p>Cliente: {{ $inquiry->client->name }}</p>@endcan
      <h3>Necesidad del cliente</h3><p class="inquiry-message">{{ $inquiry->message }}</p>
      @if($publicService)<a href="{{ route('catalog.show', $inquiry->service_id) }}">Consultar publicación actual</a>@else<p class="account-note">La publicación ya no está disponible para nuevas solicitudes. Tu seguimiento se conserva.</p>@endif
      <dl class="inquiry-dates"><div><dt>Enviada</dt><dd><x-inquiry-time :at="$inquiry->created_at" /></dd></div><div><dt>Actualizada</dt><dd><x-inquiry-time :at="$inquiry->updated_at" /></dd></div></dl>
      <x-inquiry-actions :inquiry="$inquiry" />
      @if($inquiry->status === \App\Enums\InquiryStatus::Completed)<p class="account-note">Finalizada es un estado operativo. No confirma pago, garantía, satisfacción del cliente ni un contrato formal.</p>@else<p class="account-help">Aceptar una solicitud no crea una reserva, pago ni contrato automático.</p>@endif
    </section>
    <section class="account-card inquiry-timeline"><h2>Historial de decisiones</h2><p class="account-help">Horario de Ciudad de México.</p><ol>@foreach($inquiry->timeline() as $event)<li><strong>{{ $event['label'] }}</strong><x-inquiry-time :at="$event['at']" /></li>@endforeach</ol></section>
  </div>
  <x-inquiry-conversation :inquiry="$inquiry" :messages="$messages" :read-marker="$readMarker" :unread-messages-count="$unreadMessagesCount" :message-read-token="$messageReadToken" />
  <nav class="inquiry-actions" aria-label="Volver a mis paneles">@can(\App\Enums\Permission::ManageOwnInquiries->value)<a href="{{ route('inquiries.index') }}">Mis solicitudes</a>@endcan @can(\App\Enums\Permission::ManageReceivedInquiries->value)<a href="{{ route('inquiries.received') }}">Solicitudes recibidas</a>@endcan</nav>
</x-account-layout>
