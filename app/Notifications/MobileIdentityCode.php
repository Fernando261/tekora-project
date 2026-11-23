<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MobileIdentityCode extends Notification
{
    public function __construct(public readonly string $token, public readonly bool $registration) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->registration ? 'Confirma el correo de tu cuenta Tekora' : 'Recupera tu contraseña de Tekora')
            ->line('Copia este código en la aplicación de Tekora para continuar.')
            ->line($this->token)
            ->line('Si no solicitaste esta operación, puedes ignorar este correo.');
    }
}
