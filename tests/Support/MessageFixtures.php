<?php

namespace Tests\Support;

use App\Models\ServiceInquiry;
use App\Models\ServiceInquiryMessage;
use App\Models\User;
use App\Services\InquiryMessaging;
use Illuminate\Support\Str;

trait MessageFixtures
{
    use InquiryFixtures;

    protected function messageData(string $body = '¿Puedes ayudarme con el diagnóstico?', ?string $key = null): array
    {
        return ['body' => $body, 'idempotency_key' => $key ?? (string) Str::uuid()];
    }

    protected function message(User $author, ServiceInquiry $inquiry, string $body = 'Mensaje privado de prueba.', ?string $key = null): ServiceInquiryMessage
    {
        return app(InquiryMessaging::class)->send($author, $inquiry->id, $body, $key ?? (string) Str::uuid())['message'];
    }
}
