@props(['service'])
@php($location = $service->publicLocation())
<span class="trust-location">{{ $location['municipality']['name'] ?? ($service->modality === \App\Enums\ServiceModality::Remote ? 'Cobertura remota' : 'Ubicación no especificada') }}</span>
