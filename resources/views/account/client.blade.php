<x-account-layout title="Cliente">
  <header class="account-heading"><span class="account-eyebrow">Experiencia de cliente</span><h1>Tu espacio para contratar</h1><p>Esta es tu cuenta de cliente. También puedes ofrecer servicios desde la misma sesión.</p></header>
  <section class="account-card"><h2>Seguimiento de tus necesidades</h2><p>Consulta lo que has solicitado a los prestadores y su respuesta.</p><a class="account-button" href="{{ route('inquiries.index') }}">Mis solicitudes</a></section>
  <section class="account-card"><h2>Encuentra servicios y talento</h2><p>Explora servicios técnicos y freelance y conoce a sus prestadores.</p><a class="account-button" href="{{ route('catalog.index') }}">Explorar servicios</a> <a class="account-button secondary" href="{{ route('public-providers.index') }}">Explorar prestadores</a></section>
  <section class="account-card"><h2>Actividad anterior en MindBloom</h2><p>Puedes seguir consultando tus citas anteriores. Los perfiles profesionales nuevos no aparecen en ese directorio ni reciben sus citas.</p><a class="account-button" href="{{ route('identity.citas') }}">Consultar mis citas de MindBloom</a></section>
  <section class="account-card"><h2>¿También quieres ofrecer servicios?</h2>
    @can(\App\Enums\Permission::ManageProviderProfile->value)
      <a class="account-button secondary" href="{{ route('account.provider') }}">Cambiar a Prestador</a>
    @else
      <a class="account-button secondary" href="{{ route('account.activate-provider') }}">Activar mi perfil de prestador</a>
    @endcan
  </section>
</x-account-layout>
