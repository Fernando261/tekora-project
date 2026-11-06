@props(['horizontal' => false, 'tekora' => false])
<nav class="{{ $horizontal ? 'account-navigation' : 'menu' }}" aria-label="Experiencias de la cuenta">
  <a class="menu-item" href="{{ route('catalog.index') }}">@if($tekora)<x-tekora-icon name="search" />@endif Explorar servicios</a>
  <a class="menu-item" href="{{ route('public-providers.index') }}">@if($tekora)<x-tekora-icon name="user" />@endif Explorar prestadores</a>
  <a class="menu-item {{ request()->routeIs('account.index') ? 'active' : '' }}" href="{{ route('account.index') }}">@if($tekora)<x-tekora-icon name="grid" />@endif Mi cuenta</a>
  @auth('web')
    @php($unreadNotifications = app(\App\Services\NotificationInbox::class)->unreadCount(auth('web')->user()))
    <a class="menu-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}" href="{{ route('notifications.index') }}">@if($tekora)<x-tekora-icon name="bell" />@endif Notificaciones @if($unreadNotifications === null)<span class="account-help" aria-label="Contador de avisos temporalmente no disponible"> · Contador no disponible</span>@elseif($unreadNotifications > 0)<span aria-label="{{ $unreadNotifications }} sin leer, avisos de solicitudes, reservas y reseñas">({{ $unreadNotifications }})</span>@endif</a>
  @endauth
  @can('conversations', \App\Models\ServiceInquiry::class)
    @php($unreadMessages = app(\App\Services\InquiryReadTracking::class)->totalUnread(auth('web')->user()))
    <a class="menu-item {{ request()->routeIs('conversations.*') ? 'active' : '' }}" href="{{ route('conversations.index') }}">@if($tekora)<x-tekora-icon name="chat" />@endif Mensajes @if($unreadMessages > 0)<span aria-label="{{ $unreadMessages }} {{ $unreadMessages === 1 ? 'mensaje sin leer' : 'mensajes sin leer' }}">({{ $unreadMessages }})</span>@endif</a>
  @endcan
  @can('viewAny', \App\Models\ServiceReview::class)
    <a class="menu-item {{ request()->routeIs('reviews.*') ? 'active' : '' }}" href="{{ route('reviews.index') }}">@if($tekora)<x-tekora-icon name="star" />@endif Mis reseñas</a>
  @endcan
  @can('viewAny', \App\Models\ServiceReservation::class)
    <a class="menu-item {{ request()->routeIs('reservations.*') ? 'active' : '' }}" href="{{ route('reservations.index') }}">@if($tekora)<x-tekora-icon name="calendar" />@endif Mis reservas</a>
  @endcan
  @can(\App\Enums\Permission::ViewClientAppointments->value)
    <a class="menu-item {{ request()->routeIs('account.client', 'identity.citas') ? 'active' : '' }}" href="{{ route('account.client') }}">@if($tekora)<x-tekora-icon name="user" />@endif Cliente</a>
  @endcan
  @can(\App\Enums\Permission::ManageOwnInquiries->value)
    <a class="menu-item {{ request()->routeIs('inquiries.index') ? 'active' : '' }}" href="{{ route('inquiries.index') }}">@if($tekora)<x-tekora-icon name="briefcase" />@endif Mis solicitudes</a>
  @endcan
  @can(\App\Enums\Permission::ManageProviderProfile->value)
    <a class="menu-item {{ request()->routeIs('provider.dashboard') ? 'active' : '' }}" href="{{ route('provider.dashboard') }}">@if($tekora)<x-tekora-icon name="grid" />@endif Panel del prestador</a>
    <a class="menu-item {{ request()->routeIs('availability.*') ? 'active' : '' }}" href="{{ route('availability.edit') }}">@if($tekora)<x-tekora-icon name="clock" />@endif Disponibilidad</a>
    <a class="menu-item {{ request()->routeIs('account.provider', 'provider-profiles.*') ? 'active' : '' }}" href="{{ route('account.provider') }}">@if($tekora)<x-tekora-icon name="user" />@endif Prestador · Mi perfil</a>
  @else
    <a class="menu-item" href="{{ route('account.activate-provider') }}">@if($tekora)<x-tekora-icon name="plus" />@endif Activar prestador</a>
  @endcan
  @can('viewAny', \App\Models\Service::class)
    <a class="menu-item {{ request()->routeIs('provider-services.*') ? 'active' : '' }}" href="{{ route('provider-services.index') }}">@if($tekora)<x-tekora-icon name="briefcase" />@endif Mis servicios</a>
  @endcan
  @can(\App\Enums\Permission::ManageReceivedInquiries->value)
    <a class="menu-item {{ request()->routeIs('inquiries.received') ? 'active' : '' }}" href="{{ route('inquiries.received') }}">@if($tekora)<x-tekora-icon name="briefcase" />@endif Solicitudes recibidas</a>
  @endcan
  @can(\App\Enums\Permission::ManageAccountRoles->value)
    <a class="menu-item" href="{{ route('identity.roles.index') }}">@if($tekora)<x-tekora-icon name="shield" />@endif Administración · Roles</a>
  @endcan
  <a class="menu-item" href="{{ route('perfil') }}">@if($tekora)<x-tekora-icon name="user" />@endif Datos de mi cuenta</a>
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
