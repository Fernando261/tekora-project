@props(['profile'])
@php($professional = $profile->publicCredential())
<p class="trust-credential">
  @if($professional['status'] === 'verified')
    <strong class="trust-badge">Profesional Tekora</strong>
  @else
    <span>Verificación: {{ \App\Services\ProfessionalCredentials::LABELS[$professional['status']] }}</span>
  @endif
  @if($professional['license_number'])<span>Cédula profesional {{ $professional['license_number'] }}</span>@endif
</p>
