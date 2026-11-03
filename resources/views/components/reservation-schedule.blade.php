@props(['reservation'])
<dl class="reservation-schedule"><div><dt>Fecha</dt><dd>{{ implode('/', array_reverse(explode('-', $reservation->local_date))) }}</dd></div><div><dt>Horario · 60 minutos</dt><dd>{{ $reservation->localTime() }}–{{ $reservation->localEndTime() }}</dd></div><div><dt>Zona horaria original</dt><dd>{{ $reservation->timezone }}</dd></div></dl>
