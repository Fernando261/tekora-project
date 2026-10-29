<x-account-layout title="Notificaciones">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/catalog.css') }}"><link rel="stylesheet" href="{{ asset('css/notifications.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Tu cuenta</span><h1>Notificaciones</h1><p>Novedades de las solicitudes que envías y recibes. Abrir esta página no marca los avisos como leídos.</p></header>
  <p class="account-help">{{ $notifications->total() }} avisos. Fechas en horario de Ciudad de México.</p>
  <div class="notification-list">
    @forelse($notifications as $notification)
      <article class="account-card notification-card {{ $notification->read_at ? 'is-read' : 'is-unread' }}">
        <div class="notification-meta"><span class="notification-state">{{ $notification->read_at ? 'Leída' : 'No leída' }}</span><x-inquiry-time :at="$notification->created_at" /></div>
        <p class="account-eyebrow">{{ $notification->event->label() }}</p>
        <h2>{{ $notification->event->title() }}</h2>
        <div class="notification-actions">
          @can('view', $notification->inquiry)
            <a class="account-button secondary" href="{{ route('inquiries.show', $notification->service_inquiry_id) }}">Ver solicitud #{{ $notification->service_inquiry_id }}</a>
          @else
            <p class="account-help">El detalle no está disponible con tus permisos actuales.</p>
          @endcan
          @if(!$notification->read_at)
            <form method="POST" action="{{ route('notifications.read', $notification) }}">
              @csrf
              <button class="account-button" type="submit">Marcar como leída<span class="notification-sr-only">: {{ $notification->event->label() }}, solicitud #{{ $notification->service_inquiry_id }}</span></button>
            </form>
          @else
            <span class="account-help">Leída el <x-inquiry-time :at="$notification->read_at" /></span>
          @endif
        </div>
      </article>
    @empty
      <section class="account-card"><h2>Aún no tienes notificaciones</h2><p>Cuando recibas una solicitud o cambie su estado, encontrarás aquí el aviso correspondiente.</p><a class="account-button secondary" href="{{ route('account.index') }}">Volver a mi cuenta</a></section>
    @endforelse
  </div>
  <x-catalog-pagination :paginator="$notifications" />
</x-account-layout>
