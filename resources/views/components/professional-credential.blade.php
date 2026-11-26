@props(['profile'])
@php($professional = $profile->publicCredential())
@if($professional['badge'] || $professional['license_number'])
  <p class="trust-credential">
    @if($professional['badge'])<strong class="trust-badge">{{ $professional['badge'] }}</strong>@endif
    @if($professional['license_number'])<span>Cédula declarada: {{ $professional['license_number'] }}</span>@endif
  </p>
@endif
