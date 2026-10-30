<?php

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\InquiryReadFailure;
use App\Models\InquiryReadProgress;
use App\Models\ServiceInquiry;
use App\Models\ServiceInquiryMessage;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InquiryReadTracking
{
    use DetectsConcurrencyErrors;

    public function conversations(User $user): Builder
    {
        $client = $user->hasPermission(Permission::ManageOwnInquiries);
        $provider = $user->hasPermission(Permission::ManageReceivedInquiries);

        return ServiceInquiry::query()->where(function (Builder $query) use ($user, $client, $provider) {
            $query->whereRaw('0 = 1');
            if ($client) $query->orWhere('client_user_id', $user->id);
            if ($provider) $query->orWhereHas('service.providerProfile', fn ($profiles) => $profiles->where('user_id', $user->id));
        });
    }

    public function withUnreadCount(Builder $query, User $user): Builder
    {
        return $query->withCount(['messages as unread_messages_count' => fn (Builder $messages) => $this->unread($messages, $user)]);
    }

    public function totalUnread(User $user): int
    {
        return $this->unread(ServiceInquiryMessage::query(), $user)
            ->whereIn('service_inquiry_id', $this->conversations($user)->select('service_inquiries.id'))->count();
    }

    public function unreadFor(User $user, ServiceInquiry $inquiry): int
    {
        Gate::forUser($user)->authorize('view', $inquiry);

        return $this->unread(ServiceInquiryMessage::query(), $user)->where('service_inquiry_id', $inquiry->id)->count();
    }

    public function markerFor(User $user, ServiceInquiry $inquiry): int
    {
        Gate::forUser($user)->authorize('view', $inquiry);

        return (int) InquiryReadProgress::where('service_inquiry_id', $inquiry->id)->where('user_id', $user->id)->value('last_read_message_id');
    }

    private function unread(Builder $messages, User $user): Builder
    {
        return $messages->where('service_inquiry_messages.author_user_id', '!=', $user->id)
            ->where('service_inquiry_messages.id', '>', function ($query) use ($user) {
                $query->from('service_inquiry_reads')->selectRaw('COALESCE(MAX(last_read_message_id), 0)')
                    ->whereColumn('service_inquiry_reads.service_inquiry_id', 'service_inquiry_messages.service_inquiry_id')
                    ->where('service_inquiry_reads.user_id', $user->id);
            });
    }

    public function tokenFor(User $user, ServiceInquiry $inquiry, Collection $page): ?string
    {
        Gate::forUser($user)->authorize('view', $inquiry);
        // Solo mensajes recibidos realmente incluidos en la página servida; nunca MAX global al hacer POST.
        $ids = $page->where('author_user_id', '!=', $user->id)->pluck('id');
        if ($ids->isEmpty()) return null;
        $bound = $inquiry->messages()->where('author_user_id', '!=', $user->id)->whereIn('id', $ids)->max('id');
        if (! $bound || $bound <= $this->markerFor($user, $inquiry)) return null;

        return Crypt::encryptString(json_encode(['user_id' => $user->id, 'inquiry_id' => $inquiry->id, 'through' => (int) $bound], JSON_THROW_ON_ERROR));
    }

    public function mark(User $user, int $inquiryId, string $token): InquiryReadProgress
    {
        try {
            return DB::transaction(function () use ($user, $inquiryId, $token) {
                $inquiry = ServiceInquiry::findOrFail($inquiryId);
                Gate::forUser($user)->authorize('view', $inquiry);
                try {
                    $payload = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException $exception) {
                    $payload = null;
                }
                if (! is_array($payload) || ($payload['user_id'] ?? null) !== $user->id || ($payload['inquiry_id'] ?? null) !== $inquiry->id
                    || ! is_int($payload['through'] ?? null) || $payload['through'] < 1
                    || ! $inquiry->messages()->whereKey($payload['through'])->where('author_user_id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages(['read_token' => 'La acción de lectura no es válida. Abre de nuevo la conversación.']);
                }
                $now = now()->format('Y-m-d H:i:s');
                // Un solo UPSERT: un formulario repetido u obsoleto nunca reduce el marcador ni cambia su fecha.
                DB::statement('INSERT INTO service_inquiry_reads (service_inquiry_id, user_id, last_read_message_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?) ON CONFLICT(service_inquiry_id, user_id) DO UPDATE SET
                    last_read_message_id = excluded.last_read_message_id, updated_at = excluded.updated_at
                    WHERE service_inquiry_reads.last_read_message_id < excluded.last_read_message_id',
                    [$inquiry->id, $user->id, $payload['through'], $now, $now]);

                return InquiryReadProgress::where('service_inquiry_id', $inquiry->id)->where('user_id', $user->id)->firstOrFail();
            }, 3);
        } catch (\PDOException $exception) {
            throw new InquiryReadFailure($this->causedByConcurrencyError($exception) ? 409 : 503);
        }
    }
}
