<x-account-layout title="Cédula profesional">
  <section class="account-card trust-policy">
    <h1>Completa tu declaración de cédula</h1>
    <p>Indica si cuentas con una cédula. Puedes actualizar tu respuesta desde tu perfil de prestador.</p>
    <form class="account-form" method="POST" action="{{ route('trust.onboarding.store') }}">
      @csrf
      <x-professional-license-fields required />
      <button class="account-button">Guardar y continuar</button>
    </form>
  </section>
</x-account-layout>
