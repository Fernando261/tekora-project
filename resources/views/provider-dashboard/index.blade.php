<x-account-layout title="Panel del prestador" :dashboard="true">
  <div class="tk-dashboard" data-dashboard="provider">
  @push('styles')
    <link rel="stylesheet" href="{{ asset('css/provider-dashboard.css') }}">
  @endpush
  <div class="tk-dashboard-heading-row"><header class="account-heading dashboard-heading"><span class="account-eyebrow">Tu actividad como prestador</span><h1>{{ $dashboard['profile']?->display_name ?? 'Panel del prestador' }}</h1><p>Una vista de tus servicios, tus próximas reservas y lo que necesita tu atención.</p></header>@if($dashboard['profile'])<a class="account-button" href="{{ route('provider-services.create') }}"><x-tekora-icon name="plus" /> Crear servicio</a>@endif</div>
  @if(! $dashboard['profile'])
    <section class="account-card tk-dashboard-empty"><x-tekora-icon name="briefcase" /><h2>Completa tu perfil para empezar</h2><p>Tu cuenta ya tiene acceso de prestador. Crea tu perfil profesional para consultar tu actividad y ofrecer servicios.</p><a class="account-button" href="{{ route('account.provider') }}">Completar mi perfil</a></section>
  @else
    <section class="dashboard-period account-card" aria-label="Periodo de actividad">
      <div><h2>Tu resumen</h2><p>Actividad del {{ $dashboard['window']['label'] }} · Ciudad de México.</p><p class="account-help">Los indicadores de estado actual se consultan al momento y no cambian con el periodo.</p></div>
      <form action="{{ route('provider.dashboard') }}" method="GET" class="dashboard-filter"><label for="dashboard-period">Periodo</label><div><select id="dashboard-period" name="period">@foreach([7, 30, 90] as $days)<option value="{{ $days }}" @selected($dashboard['window']['days'] === $days)>Últimos {{ $days }} días</option>@endforeach</select><button class="account-button secondary" type="submit">Aplicar</button></div></form>
    </section>
    <section class="dashboard-kpis" aria-label="Diez indicadores del prestador">@foreach($dashboard['metrics'] as $metric)<x-provider-kpi :metric="$metric" />@endforeach</section>
    <div class="dashboard-columns">
      <section class="account-card dashboard-chart" data-dashboard-section aria-labelledby="activity-title">
        <span class="dashboard-scope is-period">Del periodo</span><h2 id="activity-title">Reservas completadas por día</h2><p class="account-help">{{ $dashboard['completed'] }} completadas en {{ $dashboard['window']['days'] }} días. Fecha de la transición real, en Ciudad de México.</p>
        @if($dashboard['completed'] > 0)
          <figure><svg data-dashboard-chart viewBox="0 0 900 170" role="img" aria-labelledby="chart-title chart-description"><title id="chart-title">Actividad de reservas completadas</title><desc id="chart-description">Una barra por día, de la fecha más antigua a la más reciente. Consulta los valores exactos en la tabla por día.</desc><defs><linearGradient id="tk-provider-chart-fill" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#90e0ef"/><stop offset="1" stop-color="#0077b6"/></linearGradient></defs><line x1="0" y1="150" x2="900" y2="150" stroke="#294b67" />@foreach($dashboard['series'] as $day)<rect x="{{ $day['x'] - $day['width'] / 2 }}" y="{{ 150 - $day['height'] }}" width="{{ $day['width'] }}" height="{{ $day['height'] }}" rx="2" fill="url(#tk-provider-chart-fill)"><title>{{ $day['label'] }}: {{ $day['count'] }} completadas</title></rect>@endforeach</svg><figcaption class="dashboard-chart-axis"><span>{{ $dashboard['series'][0]['label'] }}</span><span>Máximo diario: {{ $dashboard['chart_maximum'] }}</span><span>{{ $dashboard['series'][count($dashboard['series']) - 1]['label'] }}</span></figcaption></figure>
        @else
          <p class="dashboard-empty">Todavía no hay reservas completadas en este periodo.</p>
        @endif
        <details class="dashboard-data"><summary>Consultar datos por día</summary><div class="dashboard-table-scroll" tabindex="0" role="region" aria-label="Datos diarios de reservas completadas"><table><caption>Reservas completadas por fecha local</caption><thead><tr><th scope="col">Fecha</th><th scope="col">Completadas</th></tr></thead><tbody>@foreach($dashboard['series'] as $day)<tr><th scope="row"><time datetime="{{ $day['date'] }}">{{ $day['label'] }}</time></th><td>{{ $day['count'] }}</td></tr>@endforeach</tbody></table></div></details>
      </section>
      <section class="account-card" data-dashboard-section aria-labelledby="attention-title"><span class="dashboard-scope">Estado actual</span><h2 id="attention-title">Solicitudes por atender</h2><p class="account-help">{{ $dashboard['pending'] }} pendientes. Se muestran primero las más antiguas, hasta cinco.</p>
        <ul class="dashboard-list">@forelse($dashboard['attention'] as $inquiry)<li><a href="{{ $inquiry['url'] }}">Solicitud #{{ $inquiry['id'] }} · {{ $inquiry['title'] }}</a><time datetime="{{ $inquiry['iso'] }}">Recibida el {{ $inquiry['date'] }}</time></li>@empty<li class="dashboard-empty">No tienes solicitudes pendientes por atender.</li>@endforelse</ul>
        @if($dashboard['requested'] > 0)<p class="account-note">También tienes {{ $dashboard['requested'] }} reservas futuras por confirmar. <a href="{{ route('reservations.index') }}">Revisar reservas</a></p>@endif
        <a href="{{ route('inquiries.received') }}">Ver todas las solicitudes recibidas →</a>
      </section>
    </div>
    <section class="account-card" data-dashboard-section aria-labelledby="agenda-title"><div class="dashboard-section-heading"><div><span class="dashboard-scope">Estado actual</span><h2 id="agenda-title">Tu próxima agenda</h2><p class="account-help">Solo reservas confirmadas con inicio futuro. Horarios de Ciudad de México.</p></div><a href="{{ route('reservations.index') }}">Ver todas las reservas →</a></div>
      <ol class="dashboard-list dashboard-agenda">@forelse($dashboard['agenda'] as $reservation)<li><time datetime="{{ $reservation['iso'] }}">{{ $reservation['date'] }}–{{ $reservation['end'] }}</time><a href="{{ $reservation['url'] }}">{{ $reservation['title'] }} <span class="dashboard-note">· Reserva #{{ $reservation['id'] }}</span></a></li>@empty<li class="dashboard-empty">No tienes próximas reservas confirmadas.</li>@endforelse</ol>
      @if($dashboard['upcoming'] > count($dashboard['agenda']))<p class="account-help">Se muestran las cinco reservas más próximas de {{ $dashboard['upcoming'] }}.</p>@endif
    </section>
  @endif
  <section class="account-card" data-dashboard-section aria-labelledby="quick-links-title"><h2 id="quick-links-title">Gestiona tu actividad</h2><nav class="dashboard-shortcuts" aria-label="Accesos rápidos del prestador"><a href="{{ route('provider-services.index') }}"><x-tekora-icon name="briefcase" />Mis servicios</a><a href="{{ route('availability.edit') }}"><x-tekora-icon name="clock" />Disponibilidad</a><a href="{{ route('inquiries.received') }}"><x-tekora-icon name="briefcase" />Solicitudes recibidas</a><a href="{{ route('conversations.index') }}"><x-tekora-icon name="chat" />Mensajes</a><a href="{{ route('reservations.index') }}"><x-tekora-icon name="calendar" />Reservas</a><a href="{{ route('notifications.index') }}"><x-tekora-icon name="bell" />Notificaciones</a><a href="{{ route('account.provider') }}"><x-tekora-icon name="user" />Mi perfil profesional</a></nav></section>
  </div>
</x-account-layout>
