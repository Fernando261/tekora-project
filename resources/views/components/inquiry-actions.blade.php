@props(['inquiry'])
<div class="inquiry-actions">
  @if($inquiry->status === \App\Enums\InquiryStatus::Pending)
    @can('accept', $inquiry)
      <form method="POST" action="{{ route('inquiries.accept', $inquiry) }}">@csrf @method('PATCH')<button class="account-button" type="submit">Aceptar solicitud</button></form>
      <form method="POST" action="{{ route('inquiries.reject', $inquiry) }}">@csrf @method('PATCH')<button class="account-button secondary" type="submit">Rechazar solicitud</button></form>
    @endcan
    @can('cancel', $inquiry)
      <form method="POST" action="{{ route('inquiries.cancel', $inquiry) }}">@csrf @method('PATCH')<button class="account-button secondary" type="submit">Cancelar solicitud</button></form>
    @endcan
  @elseif($inquiry->status === \App\Enums\InquiryStatus::Accepted)
    @can('complete', $inquiry)
      @php($completionBlocker = app(\App\Services\ReservationWorkflow::class)->inquiryCompletionBlocker($inquiry))
      @if($completionBlocker === null)
        <p class="account-help">La reserva ya fue marcada como realizada. Puedes finalizar la solicitud.</p>
        <form method="POST" action="{{ route('inquiries.complete', $inquiry) }}">@csrf @method('PATCH')<button class="account-button" type="submit">Finalizar solicitud</button></form>
      @else
        <p class="account-note">{{ $completionBlocker }}</p>
        <a href="#reservas">Consultar reservas y continuar</a>
      @endif
    @else
      <p class="account-help">La solicitud fue aceptada. Acuerda un horario con el prestador y consulta el estado de tu reserva.</p>
      <a href="#reservas">Consultar reservas</a>
    @endcan
  @endif
</div>
