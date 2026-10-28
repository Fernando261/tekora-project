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
      <form method="POST" action="{{ route('inquiries.complete', $inquiry) }}">@csrf @method('PATCH')<button class="account-button" type="submit">Marcar como finalizada</button></form>
    @endcan
  @endif
</div>
