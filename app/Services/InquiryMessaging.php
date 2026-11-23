<?php

namespace App\Services;

use App\Enums\InquiryStatus;
use App\Exceptions\InquiryMessageConflict;
use App\Exceptions\InquiryMessageUnavailable;
use App\Models\ServiceInquiry;
use App\Models\ServiceInquiryMessage;
use App\Models\User;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InquiryMessaging
{
    use DetectsConcurrencyErrors;

    /** @return array{message: ServiceInquiryMessage, created: bool} */
    public function send(User $author, int $inquiryId, string $body, string $key, ?Closure $validateNewBody = null): array
    {
        $key = strtolower($key);
        try {
            return DB::transaction(function () use ($author, $inquiryId, $body, $key, $validateNewBody) {
                $mysql = DB::connection()->getDriverName() === 'mysql';
                $query = ServiceInquiry::query();
                if ($mysql) {
                    // Read the current status under the same row lock used by decisions.
                    $query->lockForUpdate();
                }
                $inquiry = $query->findOrFail($inquiryId);
                Gate::forUser($author)->authorize('sendMessage', $inquiry);
                $existing = $this->existing($inquiry, $author, $key);
                if ($existing) {
                    return $this->replay($existing, $body);
                }

                $writableStates = [InquiryStatus::Pending, InquiryStatus::Accepted];
                // In MySQL a no-op UPDATE reports zero changed rows. SQLite still
                // needs the conditional write to acquire its writer lock.
                $writable = $mysql
                    ? in_array($inquiry->status, $writableStates, true)
                    : DB::table('service_inquiries')->where('id', $inquiryId)
                        ->whereIn('status', array_column($writableStates, 'value'))
                        ->update(['status' => DB::raw('status')]) === 1;
                if (! $writable) {
                    throw new InquiryMessageConflict;
                }
                Gate::forUser($author)->authorize('sendMessage', $inquiry->fresh());

                // Las reglas del nuevo envío no se aplican a un mensaje ya guardado.
                $validateNewBody?->__invoke($body);

                $message = new ServiceInquiryMessage;
                $message->setConnection($inquiry->getConnectionName());
                $message->service_inquiry_id = $inquiry->id;
                $message->author_user_id = $author->id;
                $message->idempotency_key = $key;
                $message->body = $body;
                $message->save();

                return ['message' => $message, 'created' => true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $inquiry = ServiceInquiry::findOrFail($inquiryId);
            Gate::forUser($author)->authorize('sendMessage', $inquiry);
            $existing = $this->existing($inquiry, $author, $key);
            if ($existing) {
                return $this->replay($existing, $body);
            }
            throw new InquiryMessageUnavailable;
        } catch (\PDOException $exception) {
            // Incluye QueryException y DeadlockException de transacciones anidadas,
            // sin propagar SQL, bindings ni una excepción previa con texto privado.
            if ($this->causedByConcurrencyError($exception)) {
                throw new InquiryMessageConflict('Hay otra operación en curso. Revisa la conversación y reintenta el mismo envío.');
            }
            throw new InquiryMessageUnavailable;
        }
    }

    private function existing(ServiceInquiry $inquiry, User $author, string $key): ?ServiceInquiryMessage
    {
        return $inquiry->messages()->where('author_user_id', $author->id)->where('idempotency_key', $key)->first();
    }

    private function replay(ServiceInquiryMessage $message, string $body): array
    {
        if ($message->body !== $body) {
            throw new InquiryMessageConflict('Este identificador de envío ya se usó para otro texto. Abre de nuevo la solicitud para preparar un mensaje nuevo.');
        }

        // Un reintento tras el cierre confirma el envío anterior; nunca crea un mensaje tardío.
        return ['message' => $message, 'created' => false];
    }
}
