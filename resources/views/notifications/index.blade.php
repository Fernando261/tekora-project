<x-account-layout :operations="true" title="Notificaciones">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/catalog.css') }}"><link rel="stylesheet" href="{{ asset('css/notifications.css') }}">@endpush
  <x-ops-heading variant="connect"><span class="account-eyebrow">Tu cuenta</span><h1>Notificaciones</h1><p>Novedades de tus solicitudes, reservas y reseñas. Abrir esta página no marca los avisos como leídos.</p></x-ops-heading>
  <p class="account-help">{{ $notifications->total() }} avisos. Fechas en horario de Ciudad de México.</p>
  <div class="notification-list">
    @forelse($notifications as $notification)
      <article data-ops-card class="account-card notification-card {{ $notification->read_at ? 'is-read' : 'is-unread' }}">
        <div class="notification-meta"><span class="notification-state">{{ $notification->read_at ? 'Leída' : 'No leída' }}</span><x-inquiry-time :at="$notification->created_at" /></div>
        <p class="account-eyebrow">{{ $notification->category }} · {{ $notification->event->label() }}</p>
        <h2>{{ $notification->event->title() }}</h2>
        <div class="notification-actions">
          @if($notification->detail_url)
            <a class="account-button secondary" href="{{ $notification->detail_url }}">{{ $notification->detail_label }}</a>
          @else
            <p class="account-help">{{ $notification->unavailable_message }}</p>
          @endif
          @if(!$notification->read_at)
            <form method="POST" action="{{ $notification->read_url }}">
              @csrf
              <button class="account-button" type="submit">Marcar como leída<span class="notification-sr-only">: {{ $notification->category }} · {{ $notification->event->label() }}, aviso #{{ $notification->id }}</span></button>
            </form>
          @else
            <span class="account-help">Leída el <x-inquiry-time :at="$notification->read_at" /></span>
          @endif
        </div>
      </article>
    @empty
      <section class="account-card"><h2>Aún no tienes notificaciones</h2><p>Cuando haya novedades de tus solicitudes, reservas o reseñas, encontrarás aquí el aviso correspondiente.</p><a class="account-button secondary" href="{{ route('account.index') }}">Volver a mi cuenta</a></section>
    @endforelse
  </div>
  <x-catalog-pagination :paginator="$notifications" />
</x-account-layout>
