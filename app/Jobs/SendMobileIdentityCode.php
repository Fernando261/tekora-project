<?php

namespace App\Jobs;

use App\Notifications\MobileIdentityCode;
use App\Services\AccountPasswords;
use App\Services\MobileRegistrationProof;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Throwable;

final class SendMobileIdentityCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $email, private readonly bool $registration) {}

    public static function canDeliver(): bool
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        return in_array($transport, ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'], true)
            || ($transport === 'array' && app()->environment('testing'));
    }

    public function handle(AccountPasswords $passwords, MobileRegistrationProof $proof): void
    {
        try {
            if (! self::canDeliver()) {
                Log::warning('Entrega de identidad móvil no disponible.');

                return;
            }

            if ($this->registration) {
                Notification::route('mail', $this->email)->notify(new MobileIdentityCode($proof->issue($this->email), true));

                return;
            }

            $account = $passwords->mobileAccount($this->email);
            if ($account) {
                Password::broker()->sendResetLink(['email' => $account->email],
                    static fn ($user, string $token) => $user->notify(new MobileIdentityCode($token, false)));
            }
        } catch (Throwable) {
            // Do not log mail payloads, tokens, addresses or transport credentials.
            Log::warning('No se pudo entregar el código de identidad móvil.');
        }
    }
}
