@props(['horizontal' => false])
<nav class="{{ $horizontal ? 'account-navigation' : 'menu' }}" aria-label="Experiencias de la cuenta">
  <a class="menu-item" href="{{ route('catalog.index') }}">Explorar servicios</a>
  <a class="menu-item" href="{{ route('public-providers.index') }}">Explorar prestadores</a>
  <a class="menu-item {{ request()->routeIs('account.index') ? 'active' : '' }}" href="{{ route('account.index') }}">Mi cuenta</a>
  @auth('web')
    @php($unreadNotifications = auth('web')->user()->inquiryNotifications()->unread()->count())
    <a class="menu-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}" href="{{ route('notifications.index') }}">Notificaciones @if($unreadNotifications > 0)<span aria-label="{{ $unreadNotifications }} sin leer">({{ $unreadNotifications }})</span>@endif</a>
  @endauth
  @can('conversations', \App\Models\ServiceInquiry::class)
    @php($unreadMessages = app(\App\Services\InquiryReadTracking::class)->totalUnread(auth('web')->user()))
    <a class="menu-item {{ request()->routeIs('conversations.*') ? 'active' : '' }}" href="{{ route('conversations.index') }}">Mensajes @if($unreadMessages > 0)<span aria-label="{{ $unreadMessages }} {{ $unreadMessages === 1 ? 'mensaje sin leer' : 'mensajes sin leer' }}">({{ $unreadMessages }})</span>@endif</a>
  @endcan
  @can(\App\Enums\Permission::ViewClientAppointments->value)
    <a class="menu-item {{ request()->routeIs('account.client', 'identity.citas') ? 'active' : '' }}" href="{{ route('account.client') }}">Cliente</a>
  @endcan
  @can(\App\Enums\Permission::ManageOwnInquiries->value)
    <a class="menu-item {{ request()->routeIs('inquiries.index') ? 'active' : '' }}" href="{{ route('inquiries.index') }}">Mis solicitudes</a>
  @endcan
  @can(\App\Enums\Permission::ManageProviderProfile->value)
    <a class="menu-item {{ request()->routeIs('availability.*') ? 'active' : '' }}" href="{{ route('availability.edit') }}">Disponibilidad</a>
    <a class="menu-item {{ request()->routeIs('account.provider', 'provider-profiles.*') ? 'active' : '' }}" href="{{ route('account.provider') }}">Prestador · Mi perfil</a>
  @else
    <a class="menu-item" href="{{ route('account.activate-provider') }}">Activar prestador</a>
  @endcan
  @can('viewAny', \App\Models\Service::class)
    <a class="menu-item {{ request()->routeIs('provider-services.*') ? 'active' : '' }}" href="{{ route('provider-services.index') }}">Mis servicios</a>
  @endcan
  @can(\App\Enums\Permission::ManageReceivedInquiries->value)
    <a class="menu-item {{ request()->routeIs('inquiries.received') ? 'active' : '' }}" href="{{ route('inquiries.received') }}">Solicitudes recibidas</a>
  @endcan
  @can(\App\Enums\Permission::ManageAccountRoles->value)
    <a class="menu-item" href="{{ route('identity.roles.index') }}">Administración · Roles</a>
  @endcan
  <a class="menu-item" href="{{ route('perfil') }}">Datos de mi cuenta</a>
  <details class="account-legacy">
    <summary class="menu-item">MindBloom anterior</summary>
    <div class="submenu open">
      <a class="submenu-item" href="{{ route('home') }}">Inicio MindBloom</a>
      <a class="submenu-item" href="{{ route('comunidad') }}">Comunidad</a>
      <a class="submenu-item" href="{{ url('/conferencias') }}">Conferencias</a>
      <a class="submenu-item" href="{{ route('user.citas') }}">Citas MindBloom</a>
      <a class="submenu-item" href="{{ route('profesionistas.index') }}">Directorio MindBloom</a>
    </div>
  </details>
  <form method="POST" action="{{ route('logout') }}" class="logout-form">
    @csrf
    <button class="menu-item logout-btn" type="submit">Cerrar sesión</button>
  </form>
</nav>
