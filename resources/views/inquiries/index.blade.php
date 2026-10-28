<x-account-layout :title="$received ? 'Solicitudes recibidas' : 'Mis solicitudes'">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/catalog.css') }}"><link rel="stylesheet" href="{{ asset('css/inquiries.css') }}">@endpush
  <header class="account-heading"><span class="account-eyebrow">Experiencia de {{ $received ? 'prestador' : 'cliente' }}</span><h1>{{ $received ? 'Solicitudes recibidas' : 'Mis solicitudes' }}</h1><p>{{ $received ? 'Revisa las necesidades enviadas a tus servicios y decide cómo continuar.' : 'Consulta las solicitudes que has enviado y su seguimiento.' }}</p></header>
  <form class="account-card inquiry-filters" method="GET" action="{{ route($received ? 'inquiries.received' : 'inquiries.index') }}">
    <div><label for="status">Estado</label><select id="status" name="status"><option value="">Todos los estados</option>@foreach(\App\Enums\InquiryStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
    <button class="account-button" type="submit">Filtrar</button><a href="{{ route($received ? 'inquiries.received' : 'inquiries.index') }}">Limpiar filtros</a>
  </form>
  <p class="account-help">{{ $inquiries->total() }} solicitudes. Fechas en horario de Ciudad de México.</p>
  <div class="inquiry-list">
    @forelse($inquiries as $inquiry)
      <article class="account-card inquiry-card">
        <div class="inquiry-card-heading"><span>Solicitud #{{ $inquiry->id }}</span><x-inquiry-status :status="$inquiry->status" /></div>
        <h2>{{ $inquiry->service_title }}</h2>
        <p>{{ $received ? 'Cliente: '.$inquiry->client->name : 'Prestador: '.$inquiry->service->providerProfile->display_name }}</p>
        <p class="inquiry-message">{{ \Illuminate\Support\Str::limit($inquiry->message, 180) }}</p>
        <dl class="inquiry-dates"><div><dt>Enviada</dt><dd><x-inquiry-time :at="$inquiry->created_at" /></dd></div><div><dt>Actualizada</dt><dd><x-inquiry-time :at="$inquiry->updated_at" /></dd></div></dl>
        <a class="account-button secondary" href="{{ route('inquiries.show', $inquiry) }}">{{ $received ? 'Revisar solicitud' : 'Ver seguimiento' }}<span class="inquiry-sr-only"> #{{ $inquiry->id }}</span></a>
      </article>
    @empty
      <section class="account-card"><h2>{{ $received ? 'No hay solicitudes recibidas con estos filtros' : 'No tienes solicitudes con estos filtros' }}</h2><p>{{ $received ? 'Las solicitudes de tus servicios aparecerán aquí.' : 'Explora el catálogo y encuentra un servicio para tu necesidad.' }}</p>@if(!$received)<a class="account-button" href="{{ route('catalog.index') }}">Explorar servicios</a>@endif</section>
    @endforelse
  </div>
  <x-catalog-pagination :paginator="$inquiries" />
</x-account-layout>
