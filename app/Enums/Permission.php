<?php

namespace App\Enums;

enum Permission: string
{
    case ViewClientAppointments = 'client.appointments.view';
    case ManageProviderProfile = 'provider.profile.manage';
    case ReviewProviderProfiles = 'provider.profile.review';
    case ManageAccountRoles = 'accounts.roles.manage';

    /** @return list<RoleName> */
    public function roles(): array
    {
        return match ($this) {
            self::ViewClientAppointments => [RoleName::Client],
            self::ManageProviderProfile => [RoleName::Provider],
            self::ReviewProviderProfiles, self::ManageAccountRoles => [RoleName::Admin],
        };
    }
}
