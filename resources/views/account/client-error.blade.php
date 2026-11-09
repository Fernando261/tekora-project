<x-identity-error-layout title="Panel del cliente">
  <section class="account-card tk-auth-card"><h1>No se pudo mostrar el panel del cliente</h1><p role="alert">{{ $message }}</p><a class="account-button" href="{{ route('account.client') }}">Volver al panel del cliente</a></section>
</x-identity-error-layout>
