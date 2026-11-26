<x-account-layout :operations="true" title="Certificación profesional">
  <x-flash-messages />
  <section class="account-card trust-policy">
    <h1>Certificación profesional</h1>
    <p>Estado: <strong>{{ \App\Services\ProfessionalCredentials::LABELS[$credential?->status ?? 'unverified'] }}</strong></p>
    <p>Una nueva cédula o documento requiere otra revisión. Los certificados se mantienen privados y solo puede consultarlos su titular y el personal autorizado.</p>
    <form class="account-form" method="POST" enctype="multipart/form-data" action="{{ route('trust.update') }}">
      @csrf
      <x-professional-license-fields :credential="$credential" required />
      <label for="certificate">Certificado de respaldo (PDF, JPG o PNG; hasta 5 MB)</label>
      <input id="certificate" type="file" name="certificate" accept="application/pdf,image/jpeg,image/png">
      @error('certificate')<p role="alert">{{ $message }}</p>@enderror
      @if($credential?->evidence_path)
        <a href="{{ route('trust.download', auth('web')->id()) }}">Descargar certificado actual</a>
        <label class="trust-choice"><input type="checkbox" name="remove_certificate" value="1"> Retirar certificado actual</label>
      @endif
      <button class="account-button">Guardar certificación</button>
    </form>
  </section>
</x-account-layout>
