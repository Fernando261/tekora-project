<?php

namespace App\Enums;

enum Permission: string
{
    case ViewClientAppointments = 'client.appointments.view';
    case ManageProviderProfile = 'provider.profile.manage';
    case ManageServices = 'provider.services.manage';
    case ManageOwnInquiries = 'client.inquiries.manage';
    case ManageReceivedInquiries = 'provider.inquiries.manage';
    case ReviewProviderProfiles = 'provider.profile.review';
    case ManageAccountRoles = 'accounts.roles.manage';

    /** @return list<RoleName> */
    public function roles(): array
    {
        return match ($this) {
            self::ViewClientAppointments, self::ManageOwnInquiries => [RoleName::Client],
            self::ManageProviderProfile, self::ManageServices, self::ManageReceivedInquiries => [RoleName::Provider],
            self::ReviewProviderProfiles, self::ManageAccountRoles => [RoleName::Admin],
        };
    }
}
