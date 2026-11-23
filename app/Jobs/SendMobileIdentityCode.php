<?php

namespace App\Jobs;

use App\Notifications\MobileIdentityCode;
use App\Services\AccountPasswords;
use App\Services\MobileRegistrationProof;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Mail\Transport\SesV2Transport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunHttpTransport;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

final class SendMobileIdentityCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $email, private readonly bool $registration) {}

    public static function canDeliver(): bool
    {
        // MAIL_URL and legacy mail configuration can override the declared driver.
        try {
            $transport = Mail::mailer()->getSymfonyTransport();
        } catch (Throwable) {
            return false;
        }

        return $transport instanceof SmtpTransport || $transport instanceof SendmailTransport
            || $transport instanceof MailgunApiTransport || $transport instanceof MailgunHttpTransport
            || $transport instanceof PostmarkApiTransport || $transport instanceof SesTransport
            || $transport instanceof SesV2Transport || $transport instanceof ResendTransport
            || ($transport instanceof ArrayTransport && app()->environment('testing'));
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
