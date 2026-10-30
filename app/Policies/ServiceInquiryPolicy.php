<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ServiceInquiryPolicy
{
    public function create(Authenticatable $actor, Service $service): bool
    {
        return $actor instanceof User && $actor->hasPermission(Permission::ManageOwnInquiries)
            && ! $service->providerProfile()->where('user_id', $actor->id)->exists();
    }

    public function view(Authenticatable $actor, ServiceInquiry $inquiry): bool
    {
        return $this->cancel($actor, $inquiry) || $this->accept($actor, $inquiry);
    }

    public function sendMessage(Authenticatable $actor, ServiceInquiry $inquiry): bool
    {
        // Propiedad y roles vigentes; InquiryMessaging comprueba el estado bajo bloqueo de escritura.
        return $this->view($actor, $inquiry);
    }

    public function conversations(Authenticatable $actor): bool
    {
        return $actor instanceof User && ($actor->hasPermission(Permission::ManageOwnInquiries)
            || $actor->hasPermission(Permission::ManageReceivedInquiries));
    }

    // La policy comprueba identidad/rol/propiedad; el workflow comprueba atómicamente el estado vigente.
    public function cancel(Authenticatable $actor, ServiceInquiry $inquiry): bool
    {
        return $actor instanceof User && $actor->id === $inquiry->client_user_id
            && $actor->hasPermission(Permission::ManageOwnInquiries);
    }

    public function accept(Authenticatable $actor, ServiceInquiry $inquiry): bool
    {
        return $actor instanceof User && $actor->hasPermission(Permission::ManageReceivedInquiries)
            && $inquiry->service()->whereHas('providerProfile', fn ($profiles) => $profiles->where('user_id', $actor->id))->exists();
    }

    public function reject(Authenticatable $actor, ServiceInquiry $inquiry): bool
    {
        return $this->accept($actor, $inquiry);
    }

    public function complete(Authenticatable $actor, ServiceInquiry $inquiry): bool
    {
        return $this->accept($actor, $inquiry);
    }
}
