<x-account-layout :operations="true" title="Cédula y certificación profesional">
  <x-flash-messages />
  <section class="account-card trust-policy">
    <h1>Cédula y certificación profesional</h1>
    <form class="account-form" method="POST" action="{{ route('trust.update') }}">
      @csrf
      <x-professional-license-fields :credential="$credential" required />
      <button class="account-button">Guardar declaración</button>
    </form>
  </section>
  <section class="account-card trust-policy">
    <h2>Certificado para la insignia Profesional Tekora</h2>
    <p>Solo tú y el personal autorizado pueden consultar el certificado. Si lo cambias o retiras, la insignia se retira.</p>
    @if($credential?->evidence_path)
      <p>Estado del certificado: <strong>{{ \App\Services\ProfessionalCredentials::LABELS[$credential->status] }}</strong></p>
    @endif
    <form class="account-form" method="POST" enctype="multipart/form-data" action="{{ route('trust.certificate.update') }}">
      @csrf
      <label for="certificate">Certificado de respaldo (PDF, JPG o PNG; hasta 5 MB)</label>
      <input id="certificate" type="file" name="certificate" accept="application/pdf,image/jpeg,image/png">
      @error('certificate')<p role="alert">{{ $message }}</p>@enderror
      @if($credential?->evidence_path)
        <a href="{{ route('trust.download', auth('web')->id()) }}">Descargar certificado actual</a>
        <label class="trust-choice"><input type="checkbox" name="remove_certificate" value="1"> Retirar certificado actual</label>
      @endif
      @error('remove_certificate')<p role="alert">{{ $message }}</p>@enderror
      <button class="account-button">Guardar certificado</button>
    </form>
  </section>
</x-account-layout>
