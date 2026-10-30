<x-account-layout title="Mensajes">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/catalog.css') }}"><link rel="stylesheet" href="{{ asset('css/inquiries.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Conversaciones privadas</span><h1>Mensajes</h1><p>Solicitudes en las que participas. Primero aparecen las que tienen mensajes recibidos sin leer.</p></header>
  <p class="account-help">{{ $conversations->total() }} conversaciones. Abrir la bandeja o el detalle no marca mensajes como leídos.</p>
  <div class="inquiry-list">
    @forelse($conversations as $inquiry)
      <article class="account-card inquiry-card">
        <div class="inquiry-card-heading"><span>Solicitud #{{ $inquiry->id }}</span><x-inquiry-status :status="$inquiry->status" /></div>
        <h2>{{ $inquiry->service_title }}</h2>
        <p>{{ $inquiry->unread_messages_count }} {{ $inquiry->unread_messages_count === 1 ? 'mensaje recibido sin leer' : 'mensajes recibidos sin leer' }}</p>
        @if(!$inquiry->latest_message_id)<p class="account-help">Todavía no hay mensajes posteriores a la necesidad inicial.</p>@endif
        @can('view', $inquiry)<a class="account-button secondary" href="{{ route('inquiries.show', $inquiry) }}#conversacion">Abrir conversación #{{ $inquiry->id }}</a>@endcan
      </article>
    @empty
      <section class="account-card"><h2>Aún no tienes conversaciones</h2><p>Las solicitudes enviadas o recibidas con tus permisos actuales aparecerán aquí.</p><a class="account-button secondary" href="{{ route('account.index') }}">Volver a mi cuenta</a></section>
    @endforelse
  </div>
  <x-catalog-pagination :paginator="$conversations" />
</x-account-layout>
