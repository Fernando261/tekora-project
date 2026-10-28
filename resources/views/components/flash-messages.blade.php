@foreach(['success', 'status', 'info', 'error'] as $kind)
  @if(session($kind))
    <div class="account-alert {{ $kind === 'error' ? 'is-error' : '' }}" role="{{ $kind === 'error' ? 'alert' : 'status' }}">{{ session($kind) }}</div>
  @endif
@endforeach
@if($errors->any())
  <div class="account-alert is-error" role="alert" tabindex="-1">
    <strong>Revisa los datos antes de continuar.</strong>
    <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
  </div>
@endif
