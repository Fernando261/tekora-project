@props(['journey'])
@php
  $next = $journey['next_action']; $r = $journey['reservation']; $amount = $journey['commercial']['agreed_amount'];
  $target = match($next['code']) {
    'decide_inquiry', 'close_inquiry' => '#acciones-solicitud',
    'review_quote' => route('quotes.show', ['quote' => $next['reference']['id'], 'follow' => 1]),
    'issue_quote' => '#cotizaciones',
    'propose_schedule' => route('reservations.create', ['inquiry' => $journey['inquiry']['id'], 'follow' => 1]),
    'write_review' => route('reviews.create', ['reservation' => $next['reference']['id'], 'follow' => 1]),
    'view_review' => route('reviews.show', ['review' => $next['reference']['id'], 'follow' => 1]),
    'read_history' => '#historial',
    default => $r ? '#decision-reserva-'.$r['id'] : '#conversacion',
  };
  $stepStates = ['complete' => 'Completada', 'current' => 'Paso actual', 'pending' => 'Pendiente', 'stopped' => 'Cerrada'];
@endphp
<header class="account-heading tk-ops-heading cj-header">
  <div class="tk-ops-heading-copy"><span class="account-eyebrow">Tu contratación · Solicitud #{{ $journey['inquiry']['id'] }}</span>
    <h1>{{ $journey['inquiry']['service_title'] }}</h1>
    <p>{{ $journey['viewer_role'] === 'client' ? 'Prestador: '.$journey['participants']['provider']['display_name'] : 'Cliente: '.$journey['participants']['client']['name'] }}</p>
  <div class="cj-context"><span class="cj-role">{{ $journey['viewer_role'] === 'client' ? 'Como cliente' : 'Como prestador' }}</span><x-inquiry-status :status="\App\Enums\InquiryStatus::from($journey['inquiry']['status'])" /></div></div>
  <x-ops-scene variant="connect" />
</header>
<nav class="cj-progress" aria-label="Etapas de la contratación">
  <ol>@foreach($journey['progress']['steps'] as $index => $step)<li class="is-{{ $step['status'] }}" @if($step['status'] === 'current') aria-current="step" @endif>
    <span class="cj-step-number" aria-hidden="true">{{ $step['status'] === 'complete' ? '✓' : $index + 1 }}</span><span><strong>{{ $step['label'] }}</strong><small>{{ $stepStates[$step['status']] }}</small></span>
  </li>@endforeach</ol>
</nav>
<section class="account-card cj-next" aria-labelledby="journey-next-title" data-ops-card>
  <div><span class="account-eyebrow">Tu siguiente paso</span><h2 id="journey-next-title" data-journey-next="{{ $next['code'] }}">{{ $next['label'] }}</h2><p>{{ $next['description'] }}</p></div>
  <div class="cj-next-action">@if($next['interaction'] === 'wait')<span class="cj-wait">En espera</span><a href="#conversacion">Ir a la conversación →</a>@else<a class="account-button" href="{{ $target }}">{{ $next['label'] }} <span aria-hidden="true">→</span></a>@endif</div>
</section>
<div class="cj-overview" aria-label="Resumen de tu contratación">
  <section class="account-card"><span class="account-eyebrow">Acuerdo</span><h2>{{ $amount ? \App\Payments\WebMoney::format($amount['total_cents']) : ($journey['commercial']['mode'] === 'legacy' ? 'Acuerdo directo' : 'Importe por acordar') }}</h2>
    @if($amount)<p>MXN · Comisión Tekora del 15 % incluida. Sin cargo adicional.</p>@elseif($journey['commercial']['mode'] === 'legacy')<p>Este seguimiento conserva el recorrido habitual, sin cotización comercial.</p>@else<p>Consulta la cotización y acéptala antes de proponer un horario.</p>@endif
    <a href="#cotizaciones">Ver acuerdos e historial →</a>
  </section>
  <section class="account-card"><span class="account-eyebrow">Horario</span><h2>{{ $r ? $r['label'] : 'Por proponer' }}</h2>
    @if($r)<p>{{ $r['local_date'] }} · {{ $r['local_start'] }}–{{ $r['local_end'] }}<br>{{ $r['timezone'] }}</p>@else<p>La propuesta y la confirmación se muestran aquí cuando estén registradas.</p>@endif
    <a href="#reservas">Ver horarios y decisiones →</a>
  </section>
  <section class="account-card"><span class="account-eyebrow">Servicio</span><h2>{{ $journey['closure']['explicit_service_completed'] ? 'Realización registrada' : ($r && $r['status'] === 'confirmed' ? 'Próximo servicio' : 'Aún no realizado') }}</h2>
    <p>{{ $journey['closure']['inquiry_completed'] ? 'Solicitud finalizada. Tu historial se conserva.' : 'La realización y el cierre son pasos explícitos. Todavía no se realizan cobros en Tekora.' }}</p><a href="#cierre">Ver cierre y reseña →</a>
  </section>
</div>
<nav class="cj-links" aria-label="Secciones de esta contratación"><a href="#inquiry-service-title">Detalles</a><a href="#cotizaciones">Cotizaciones</a><a href="#reservas">Reservas</a><a href="#conversacion">Conversación @if($journey['conversation']['unread_count'])({{ $journey['conversation']['unread_count'] }} sin leer)@endif</a><a href="#historial">Historial</a><a href="#cierre">Cierre y reseña</a></nav>
