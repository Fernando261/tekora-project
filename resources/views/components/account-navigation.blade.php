@props(['horizontal' => false, 'tekora' => false])
@php($cinematic = $tekora && ! $horizontal)
<nav class="{{ $horizontal ? 'account-navigation' : 'menu' }} {{ $cinematic ? 'tk-sidebar-navigation' : '' }}" aria-label="Experiencias de la cuenta">
  <x-account-navigation-group label="Explorar" :enabled="$cinematic">
    <x-account-navigation-link :tekora="$tekora" icon="search" :href="route('catalog.index')" :active="$cinematic && request()->routeIs('catalog.*')">Explorar servicios</x-account-navigation-link>
    <x-account-navigation-link :tekora="$tekora" icon="user" :href="route('public-providers.index')" :active="$cinematic && request()->routeIs('public-providers.*')">Explorar prestadores</x-account-navigation-link>
  </x-account-navigation-group>
  <x-account-navigation-group label="Mi cuenta" :enabled="$cinematic">
    <x-account-navigation-link :tekora="$tekora" icon="grid" :href="route('account.index')" :active="request()->routeIs('account.index')">Mi cuenta</x-account-navigation-link>
    @auth('web')
      @php($unreadNotifications = app(\App\Services\NotificationInbox::class)->unreadCount(auth('web')->user()))
      <x-account-navigation-link :tekora="$tekora" icon="bell" :href="route('notifications.index')" :active="request()->routeIs('notifications.*')">Notificaciones
        <x-slot:badge>
          @if($unreadNotifications === null)
            <span class="account-help {{ $cinematic ? 'tk-nav-status' : '' }}" aria-label="Contador de avisos temporalmente no disponible">@if($cinematic)<span aria-hidden="true">—</span><span class="tk-nav-sr">Contador no disponible</span>@else · Contador no disponible @endif</span>
          @elseif($unreadNotifications > 0)
            <span @class(['tk-nav-badge' => $cinematic]) aria-label="{{ $unreadNotifications }} sin leer, avisos de solicitudes, reservas y reseñas">{{ $cinematic ? $unreadNotifications : '('.$unreadNotifications.')' }}</span>
          @endif
        </x-slot:badge>
      </x-account-navigation-link>
    @endauth
    @can('conversations', \App\Models\ServiceInquiry::class)
      @php($unreadMessages = app(\App\Services\InquiryReadTracking::class)->totalUnread(auth('web')->user()))
      <x-account-navigation-link :tekora="$tekora" icon="chat" :href="route('conversations.index')" :active="request()->routeIs('conversations.*')">Mensajes
        <x-slot:badge>@if($unreadMessages > 0)<span @class(['tk-nav-badge tk-nav-badge-messages' => $cinematic]) aria-label="{{ $unreadMessages }} {{ $unreadMessages === 1 ? 'mensaje sin leer' : 'mensajes sin leer' }}">{{ $cinematic ? $unreadMessages : '('.$unreadMessages.')' }}</span>@endif</x-slot:badge>
      </x-account-navigation-link>
    @endcan
    @can('viewAny', \App\Models\ServiceReview::class)
      <x-account-navigation-link :tekora="$tekora" icon="star" :href="route('reviews.index')" :active="request()->routeIs('reviews.*')">Mis reseñas</x-account-navigation-link>
    @endcan
    @can('viewAny', \App\Models\ServiceReservation::class)
      <x-account-navigation-link :tekora="$tekora" icon="calendar" :href="route('reservations.index')" :active="request()->routeIs('reservations.*')">Mis reservas</x-account-navigation-link>
    @endcan
  </x-account-navigation-group>
  <x-account-navigation-group label="Cliente" :enabled="$cinematic">
    @can(\App\Enums\Permission::ViewClientAppointments->value)
      <x-account-navigation-link :tekora="$tekora" icon="user" :href="route('account.client')" :active="request()->routeIs('account.client', 'identity.citas')">Cliente</x-account-navigation-link>
    @endcan
    @can(\App\Enums\Permission::ManageOwnInquiries->value)
      <x-account-navigation-link :tekora="$tekora" icon="briefcase" :href="route('inquiries.index')" :active="request()->routeIs('inquiries.index')">Mis solicitudes</x-account-navigation-link>
    @endcan
  </x-account-navigation-group>
  <x-account-navigation-group label="Prestador" :enabled="$cinematic">
    @can(\App\Enums\Permission::ManageProviderProfile->value)
      <x-account-navigation-link :tekora="$tekora" icon="grid" :href="route('provider.dashboard')" :active="request()->routeIs('provider.dashboard')">Panel del prestador</x-account-navigation-link>
      <x-account-navigation-link :tekora="$tekora" icon="clock" :href="route('availability.edit')" :active="request()->routeIs('availability.*')">Disponibilidad</x-account-navigation-link>
      <x-account-navigation-link :tekora="$tekora" icon="user" :href="route('account.provider')" :active="request()->routeIs('account.provider', 'provider-profiles.*')">Prestador · Mi perfil</x-account-navigation-link>
    @else
      <x-account-navigation-link :tekora="$tekora" icon="plus" :href="route('account.activate-provider')" :active="$cinematic && request()->routeIs('account.activate-provider')">Activar prestador</x-account-navigation-link>
    @endcan
    @can('viewAny', \App\Models\Service::class)
      <x-account-navigation-link :tekora="$tekora" icon="briefcase" :href="route('provider-services.index')" :active="request()->routeIs('provider-services.*')">Mis servicios</x-account-navigation-link>
    @endcan
    @can(\App\Enums\Permission::ManageReceivedInquiries->value)
      <x-account-navigation-link :tekora="$tekora" icon="briefcase" :href="route('inquiries.received')" :active="request()->routeIs('inquiries.received')">Solicitudes recibidas</x-account-navigation-link>
    @endcan
  </x-account-navigation-group>
  <x-account-navigation-group label="Administración" :enabled="$cinematic">
    @can(\App\Enums\Permission::ManageAccountRoles->value)
      <x-account-navigation-link :tekora="$tekora" icon="shield" :href="route('identity.roles.index')" :active="$cinematic && request()->routeIs('identity.roles.*')">Administración · Roles</x-account-navigation-link>
    @endcan
  </x-account-navigation-group>
  <x-account-navigation-group label="Preferencias y legado" :enabled="$cinematic">
    <x-account-navigation-link :tekora="$tekora" icon="user" :href="route('perfil')" :active="$cinematic && request()->routeIs('perfil')">Datos de mi cuenta</x-account-navigation-link>
    <details class="account-legacy">
      <summary class="menu-item">@if($cinematic)<x-tekora-icon name="grid" /><span class="tk-nav-label">MindBloom anterior</span><span class="tk-nav-chevron" aria-hidden="true">⌄</span>@else MindBloom anterior @endif</summary>
      <div class="submenu open">
        <a class="submenu-item" href="{{ route('home') }}">Inicio MindBloom</a>
        <a class="submenu-item" href="{{ route('comunidad') }}">Comunidad</a>
        <a class="submenu-item" href="{{ url('/conferencias') }}">Conferencias</a>
        <a class="submenu-item" href="{{ route('user.citas') }}">Citas MindBloom</a>
        <a class="submenu-item" href="{{ route('profesionistas.index') }}">Directorio MindBloom</a>
      </div>
    </details>
  </x-account-navigation-group>
  <x-account-navigation-group label="Salir" :enabled="$cinematic">
    <form method="POST" action="{{ route('logout') }}" class="logout-form">
      @csrf
      <button class="menu-item logout-btn" type="submit">@if($cinematic)<x-tekora-icon name="arrow" /><span class="tk-nav-label">Cerrar sesión</span>@else Cerrar sesión @endif</button>
    </form>
  </x-account-navigation-group>
</nav>
