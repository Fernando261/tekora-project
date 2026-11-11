<x-account-layout title="Panel del cliente" :dashboard="true">
  <div class="tk-dashboard" data-dashboard="client">
    <x-dashboard-navigation kind="client" :sections="[['summary','Resumen','grid'],['reservations','Reservas','calendar'],['requests','Solicitudes','chat']]" />
    <div class="tk-dashboard-heading-row">
      <header class="account-heading"><span class="account-eyebrow">Tu espacio para contratar · Experiencia de cliente</span><h1>Hola, {{ $user->name }}<span aria-hidden="true">.</span></h1><p>Tus ideas, en movimiento. Sigue tus solicitudes, encuentra tus próximas reservas y mantén la conversación.</p></header>
      <a class="account-button" href="{{ route('catalog.index') }}"><x-tekora-icon name="search" /> Explorar servicios</a>
    </div>
    <div class="tk-client-meta"><p>Resumen al <time datetime="{{ $summary['as_of']->toIso8601String() }}">{{ $summary['as_of']->format('d/m/Y H:i:s') }}</time> · {{ $summary['timezone'] }}.</p><a href="{{ route('notifications.index') }}"><x-tekora-icon name="bell" /> @if($summary['notifications'] === null) Avisos temporalmente no disponibles @else {{ $summary['notifications'] }} notificaciones sin leer @endif · Cuenta completa <x-tekora-icon name="arrow" /></a></div>
    <div id="client-panels" class="tk-dashboard-modules" data-dashboard-panels>
    <section id="client-summary" class="dashboard-kpis tk-client-kpis tk-primary-kpis" data-dashboard-module="summary" aria-label="Tu actividad como cliente">
      @foreach([
        ['active', 'Solicitudes activas', 'Pendientes o aceptadas, enviadas por ti.', 'inquiries.index', 'briefcase'],
        ['confirmed', 'Reservas confirmadas futuras', 'El prestador confirmó la reserva y su inicio sigue siendo futuro.', 'reservations.index', 'calendar'],
        ['requested', 'Propuestas por confirmar', 'Horarios futuros propuestos por ti, pendientes de confirmación. Todavía no son reservas confirmadas.', 'reservations.index', 'clock'],
        ['unread', 'Mensajes como cliente', 'Mensajes recibidos sin leer en tus propias solicitudes. Excluye tu actividad como prestador.', 'conversations.index', 'chat'],
      ] as [$key, $label, $note, $route, $icon])
        <article class="account-card dashboard-kpi" data-client-kpi="{{ $key }}" data-accent="{{ ['active'=>'cyan','confirmed'=>'mint','requested'=>'amber','unread'=>'coral'][$key] }}" data-dashboard-card><div class="tk-kpi-top"><span class="dashboard-scope">Estado actual · Cliente</span><x-tekora-icon :name="$icon" /></div><h2>{{ $label }}</h2><p class="dashboard-value">{{ $summary[$key] }}</p><p class="dashboard-explanation">{{ $note }}</p><a class="dashboard-kpi-link" href="{{ route($route) }}">Consultar <span class="dashboard-sr-only">{{ $label }}</span><x-tekora-icon name="arrow" /></a></article>
      @endforeach
    </section>

      <section id="client-reservations" class="account-card tk-module-agenda tk-client-agenda" data-dashboard-module="summary reservations" aria-labelledby="client-agenda-title">
        <div class="dashboard-section-heading"><div><span class="dashboard-scope">Tu próxima conexión</span><h2 id="client-agenda-title">Lo que viene</h2></div><x-tekora-icon name="calendar" /></div>
        <p class="account-help">Solo reservas confirmadas con inicio futuro. Horarios en {{ $summary['timezone'] }}.</p>
        <ol class="dashboard-list dashboard-agenda">@forelse($summary['agenda'] as $reservation)<li><time datetime="{{ $reservation['iso'] }}">{{ $reservation['date'] }}–{{ $reservation['end'] }}</time><a href="{{ $reservation['url'] }}">{{ $reservation['title'] }} <span class="dashboard-note">· Reserva #{{ $reservation['id'] }}</span></a></li>@empty<li class="dashboard-empty">Tu próxima reserva está por llegar. Explora el catálogo o continúa una solicitud aceptada para proponer un horario.</li>@endforelse</ol>
        @if($summary['confirmed'] > count($summary['agenda']))<p class="account-help">Se muestran las cinco reservas más próximas de {{ $summary['confirmed'] }}.</p>@endif
        <a class="tk-panel-link" href="{{ route('reservations.index') }}">Ver todas mis reservas <x-tekora-icon name="arrow" /></a>
        <p class="tk-history-note">Historial por estado actual: <strong>{{ $summary['completed'] }} completadas</strong> · <strong>{{ $summary['cancelled'] }} canceladas</strong>. Estas reservas no se incluyen en tu agenda futura.</p>
      </section>
      <section id="client-requests" class="account-card tk-module-attention" data-dashboard-module="summary requests" aria-labelledby="client-requests-title"><div class="dashboard-section-heading"><div><span class="dashboard-scope">Enviadas por ti</span><h2 id="client-requests-title">Tus últimas solicitudes</h2></div><x-tekora-icon name="briefcase" /></div><p class="account-help">Hasta cinco solicitudes, de la más reciente a la más antigua. Horarios en {{ $summary['timezone'] }}.</p>
        <ul class="dashboard-list">@forelse($summary['latest'] as $inquiry)<li><a href="{{ $inquiry['url'] }}">{{ $inquiry['title'] }} <span class="dashboard-note">· Solicitud #{{ $inquiry['id'] }}</span></a><time datetime="{{ $inquiry['iso'] }}">Enviada el {{ $inquiry['date'] }}</time><x-inquiry-status :status="$inquiry['status']" /></li>@empty<li class="dashboard-empty">Tu primera idea puede empezar hoy. Encuentra un servicio y cuéntale al prestador lo que necesitas.</li>@endforelse</ul>
        <a class="tk-panel-link" href="{{ route('inquiries.index') }}">Ver todas mis solicitudes <x-tekora-icon name="arrow" /></a>
      </section>
      <section class="account-card tk-module-followup" data-dashboard-module="reservations requests" aria-labelledby="client-followup-title"><span class="dashboard-scope">Seguimiento · Cliente</span><h2 id="client-followup-title">Cada paso, en su momento</h2><p class="tk-followup-value">{{ $summary['requested'] }} <span>propuestas futuras por confirmar</span></p><p class="account-help">El prestador debe confirmar el horario. Una propuesta pendiente todavía no es una reserva confirmada.</p><a class="tk-panel-link" href="{{ route('reservations.index') }}">Revisar mis reservas <x-tekora-icon name="arrow" /></a><div class="tk-followup-history"><p><strong>{{ $summary['completed'] }}</strong> completadas</p><p><strong>{{ $summary['cancelled'] }}</strong> canceladas</p></div><p class="account-help">Historial por estado actual, separado de tu agenda futura.</p></section>
    </div>
    <section class="account-card tk-dashboard-actions" data-dashboard-section aria-labelledby="client-shortcuts"><h2 id="client-shortcuts">Haz espacio para lo que sigue</h2><nav class="dashboard-shortcuts" aria-label="Accesos rápidos del cliente">
      @foreach([['catalog.index','Explorar servicios','search'],['inquiries.index','Mis solicitudes','briefcase'],['conversations.index','Conversaciones','chat'],['reservations.index','Mis reservas','calendar'],['reviews.index','Mis reseñas','star'],['notifications.index','Notificaciones','bell']] as [$route,$label,$icon])<a href="{{ route($route) }}"><x-tekora-icon :name="$icon" /> {{ $label }}</a>@endforeach
    </nav></section>
    <section class="account-card tk-dual-account" data-dashboard-section><h2>¿También quieres ofrecer servicios?</h2><p class="account-help">Una cuenta, dos formas de conectar. Tu actividad de prestador conserva su propio panel.</p>
      @can(\App\Enums\Permission::ManageProviderProfile->value)<a class="account-button secondary" href="{{ route('provider.dashboard') }}">Cambiar a Prestador <x-tekora-icon name="arrow" /></a>@else<a class="account-button secondary" href="{{ route('account.activate-provider') }}">Activar mi perfil de prestador <x-tekora-icon name="plus" /></a>@endcan
    </section>
  </div>
</x-account-layout>
