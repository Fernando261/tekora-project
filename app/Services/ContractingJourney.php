<?php

namespace App\Services;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use App\Payments\PricingStore;
use App\Payments\QuoteReadModel;
use App\Support\BookingClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Read projection, not a second workflow. All commands still revalidate in their domain services. */
final class ContractingJourney
{
    public function __construct(private readonly PricingStore $prices, private readonly QuoteReadModel $quotes) {}

    public function forInquiry(User $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            $inquiry = ServiceInquiry::with(['client:id,name', 'service.providerProfile.user'])->findOrFail($id);
            Gate::forUser($actor)->authorize('view', $inquiry);
            $client = $actor->id === $inquiry->client_user_id && $actor->hasPermission(Permission::ManageOwnInquiries);
            $role = $client ? 'client' : 'provider';
            $now = BookingClock::observedMicros();
            $date = BookingClock::date($now);
            $nowSql = $date->format('Y-m-d H:i:s');
            $reservations = $inquiry->reservations()->where(function ($q) use ($actor, $client) {
                if ($client) {
                    $q->where('client_user_id', $actor->id);
                } else {
                    $q->whereHas('providerProfile', fn ($p) => $p->where('user_id', $actor->id));
                }
            });
            $active = (clone $reservations)->active()->orderByDesc('id')->first();
            $current = $active ?? (clone $reservations)->orderByDesc('id')->first();
            $performed = (clone $reservations)->where('status', ReservationStatus::Completed->value)->orderByDesc('id')->first();
            $recent = (clone $reservations)->with('hold')->orderByDesc('id')->limit(5)->get();
            $state = $current?->effectiveStatus($now);
            $published = $this->prices->latestPrice($inquiry->service_id);
            $keepsProtocol = $current && ! in_array($state, [ReservationStatus::Rejected, ReservationStatus::Cancelled, ReservationStatus::HoldExpired, ReservationStatus::HoldReleased], true);
            $commercial = $keepsProtocol ? (bool) $current->quote_id : $published !== null;
            $quoteId = $current?->quote_id;
            if (! $current || $commercial) {
                $base = DB::table('service_quotes as q')->leftJoin('quote_acceptances as a', 'a.quote_id', '=', 'q.id')
                    ->where('q.service_inquiry_id', $inquiry->id)->where('q.client_user_id', $inquiry->client_user_id)
                    ->where('q.service_id', $inquiry->service_id)->where('q.provider_profile_id', $inquiry->service->provider_profile_id);
                $quoteId ??= (clone $base)->whereNotNull('a.accepted_at_utc')->orderByDesc('a.accepted_at_utc')->orderByDesc('q.id')->value('q.id')
                    ?? $base->orderByDesc('q.issued_at_utc')->orderByDesc('q.id')->value('q.id');
            }
            $quote = null;
            if ($quoteId) {
                $stored = $this->prices->quote($quoteId);
                if (! $stored || $stored->inquiryId !== $inquiry->id || $stored->clientId !== $inquiry->client_user_id
                    || $stored->serviceId !== $inquiry->service_id || $stored->providerProfileId !== ($current?->provider_profile_id ?? $inquiry->service->provider_profile_id)) {
                    throw new LogicException('El acuerdo no pudo comprobarse.');
                }
                $snapshot = $this->quotes->snapshot($stored, $date->toDateTimeImmutable());
                $quote = ['id' => $stored->id, 'state' => $snapshot->acceptanceState(), 'amount' => $this->amount($stored->price->amount),
                    'issued_at' => $this->iso($stored->issuedAt), 'expires_at' => $this->iso($stored->expiresAt),
                    'accepted_at' => $snapshot->acceptance ? $this->iso($snapshot->acceptance->acceptedAt) : null];
            }
            $participants = $inquiry->client->hasPermission(Permission::ManageOwnInquiries)
                && $inquiry->service->providerProfile->user->hasPermission(Permission::ManageReceivedInquiries);
            $public = Service::publiclyVisible()->whereKey($inquiry->service_id)->exists();
            $canQuote = $client && $participants && $inquiry->status === InquiryStatus::Accepted && $published !== null && $public;
            $schedule = app(ProviderAvailability::class)->publicFor($inquiry->service->providerProfile);
            $canPropose = $participants && $inquiry->status === InquiryStatus::Accepted && $public && $schedule && ! isset($schedule['unavailable'])
                && $inquiry->service->providerProfile->weeklyIntervals()->exists()
                && (! $published || ($quote['state'] ?? null) === 'accepted');
            $closureReady = $inquiry->status === InquiryStatus::Accepted && app(ReservationWorkflow::class)->inquiryCompletionBlocker($inquiry) === null;
            $reviewReservation = $current?->status === ReservationStatus::Completed ? $current : $performed;
            $review = $client && $reviewReservation ? $reviewReservation->review()->where('client_user_id', $actor->id)->first() : null;
            $reviewEligible = $client && $reviewReservation && Gate::forUser($actor)->allows('create', [ServiceReview::class, $reviewReservation]);
            $reservation = null;
            $actions = [];
            if ($current) {
                $current->load(['hold.events', 'events', 'providerProfile.user']);
                $can = fn ($action) => $participants && Gate::forUser($actor)->allows($action, $current);
                if ($state === ReservationStatus::Requested) {
                    if ($current->starts_at_utc > $nowSql && $can('confirm')) {
                        $actions[] = 'confirm';
                    }
                    foreach (['reject', 'cancel'] as $action) {
                        if ($can($action)) {
                            $actions[] = $action;
                        }
                    }
                } elseif ($state === ReservationStatus::Held) {
                    if ($current->starts_at_utc > $nowSql && $can('commit')) {
                        $actions[] = 'commit';
                    }
                    if ($can('cancel')) {
                        $actions[] = 'cancel';
                    }
                } elseif ($state === ReservationStatus::Confirmed) {
                    if ($current->starts_at_utc > $nowSql && $can('cancel')) {
                        $actions[] = 'cancel';
                    }
                    if ($current->ends_at_utc <= $nowSql && $can('complete')) {
                        $actions[] = 'complete';
                    }
                }
                $reservation = $this->reservation($current, $now) + ['actions' => $actions,
                    'events' => $current->events->sortBy('version')->map(fn ($e) => ['version' => $e->version, 'status' => $e->to_status,
                        'actor_role' => $e->actor_user_id === null ? 'system' : ($e->actor_user_id === $inquiry->client_user_id ? 'client' : 'provider'), 'occurred_at' => $this->iso($e->occurred_at_utc)])->values()->all(),
                    'hold_events' => $current->hold ? $current->hold->events->sortBy('version')->map(fn ($e) => ['version' => $e->version, 'state' => $e->to_state,
                        'occurred_at' => BookingClock::date((int) $e->occurred_at_us)->toISOString()])->values()->all() : []];
            }
            $next = $this->next($inquiry, $role, $quote, $commercial, $reservation, $closureReady, $reviewEligible, $review,
                $reviewReservation?->id, $participants, $nowSql, $canQuote, (bool) $canPropose);
            $currentStage = $next['stage'];
            $requestDone = $inquiry->status !== InquiryStatus::Pending;
            $agreementDone = $inquiry->status !== InquiryStatus::Pending && (! $commercial || ($quote['state'] ?? null) === 'accepted');
            $performedClosure = $performed !== null && ($closureReady || $inquiry->status === InquiryStatus::Completed);
            $confirmationDone = $performedClosure || in_array($state, [ReservationStatus::Confirmed, ReservationStatus::Completed], true);
            $serviceDone = $state === ReservationStatus::Completed || $performedClosure;
            $terminal = in_array($inquiry->status, [InquiryStatus::Rejected, InquiryStatus::Cancelled], true);
            $done = ['request' => $requestDone && ! $terminal, 'agreement' => $agreementDone && ! $terminal,
                'schedule' => $confirmationDone || $state === ReservationStatus::Held, 'confirmation' => $confirmationDone, 'service' => $serviceDone];
            $steps = [];
            foreach (['request' => 'Solicitud', 'agreement' => 'Acuerdo', 'schedule' => 'Horario', 'confirmation' => 'Confirmación', 'service' => 'Servicio'] as $code => $label) {
                $steps[] = ['code' => $code, 'label' => $label, 'status' => $terminal ? 'stopped' : ($done[$code] ? 'complete' : ($code === $currentStage ? 'current' : 'pending'))];
            }

            return ['inquiry' => ['id' => $inquiry->id, 'service_id' => $inquiry->service_id, 'service_title' => $inquiry->service_title,
                'message' => $inquiry->message, 'status' => $inquiry->status->value, 'label' => $inquiry->status->label(),
                'created_at' => $inquiry->created_at->toISOString(), 'updated_at' => $inquiry->updated_at->toISOString()],
                'viewer_role' => $role, 'participants' => ['client' => ['name' => $inquiry->client->name],
                    'provider' => ['id' => $inquiry->service->providerProfile->id, 'display_name' => $inquiry->service->providerProfile->display_name]],
                'progress' => ['current_stage' => $currentStage, 'steps' => $steps, 'closed' => $inquiry->status === InquiryStatus::Completed || $terminal],
                'next_action' => $next,
                'commercial' => ['mode' => $commercial ? 'quoted' : 'legacy', 'required_for_new_proposal' => $published !== null, 'quote' => $quote,
                    'agreed_amount' => ($quote['state'] ?? null) === 'accepted' ? $quote['amount'] : null,
                    'can_issue' => $canQuote, 'can_propose' => $client && $canPropose && ! $active],
                'reservation' => $reservation,
                'review' => ['state' => ! $client ? 'client_private' : ($review ? $review->status->value : ($reviewEligible ? 'available' : 'unavailable')),
                    'eligible' => (bool) $reviewEligible, 'reservation_id' => $client ? $reviewReservation?->id : null,
                    'record' => $review ? ['id' => $review->id, 'status' => $review->status->value, 'rating' => $review->rating, 'comment' => $review->comment,
                        'created_at' => $this->iso($review->created_at), 'withdrawn_at' => $review->withdrawn_at_utc ? $this->iso($review->withdrawn_at_utc) : null] : null],
                'closure' => ['inquiry_completed' => $inquiry->status === InquiryStatus::Completed, 'can_close' => ! $client && $closureReady && $participants,
                    'explicit_service_completed' => $serviceDone],
                'history' => ['inquiry' => array_map(fn ($e) => ['label' => $e['label'], 'occurred_at' => $this->iso($e['at'])], $inquiry->timeline()),
                    'reservations' => $recent->map(fn ($r) => $this->reservation($r, $now))->all(), 'reservation_limit' => 5],
                'conversation' => ['reference' => ['type' => 'inquiry', 'id' => $inquiry->id], 'unread_count' => app(InquiryReadTracking::class)->unreadFor($actor, $inquiry),
                    'can_send' => in_array($inquiry->status, [InquiryStatus::Pending, InquiryStatus::Accepted], true) && Gate::forUser($actor)->allows('sendMessage', $inquiry)],
                'payments' => ['enabled' => false, 'required_for_confirmation' => false, 'future_checkout_point' => 'after_provider_acceptance_before_final_confirmation'],
                'confirmation_readiness' => $client && $current
                    ? app(\App\Payments\CheckoutContext::class)->forReservation($actor, $current->id, $now)['confirmation_readiness'] : null,
                'server_now' => $date->toISOString()];
        }, 3);
    }

    private function next($inquiry, string $role, ?array $quote, bool $commercial, ?array $r, bool $closure, bool $reviewEligible, $review, ?int $reviewReservation, bool $participants, string $now, bool $canQuote, bool $canPropose): array
    {
        $client = $role === 'client';
        $ref = ['type' => 'inquiry', 'id' => $inquiry->id];
        $make = fn ($code, $stage, $label, $description, $reference = null, $wait = false, $mobile = true) => ['code' => $code, 'stage' => $stage, 'label' => $label,
            'description' => $description, 'reference' => $reference ?? $ref, 'interaction' => $wait ? 'wait' : 'navigate', 'mobile_execution_available' => $mobile];
        if (in_array($inquiry->status, [InquiryStatus::Rejected, InquiryStatus::Cancelled], true)) {
            return $make('read_history', 'request', 'Consulta el historial', 'Esta solicitud está cerrada. Se conservan sus decisiones y conversación.');
        }
        if ($inquiry->status === InquiryStatus::Pending) {
            return $client
                ? $make('wait_inquiry_decision', 'request', 'Espera la respuesta del prestador', 'Tu solicitud está enviada. Puedes conversar mientras recibes una decisión.', wait: true)
                : $make('decide_inquiry', 'request', 'Revisa y responde la solicitud', 'Conoce la necesidad del cliente y acepta o rechaza con las acciones de esta solicitud.', mobile: false);
        }
        if (! $participants && $inquiry->status !== InquiryStatus::Completed) {
            return $make('wait_permissions', 'request', 'El seguimiento está limitado temporalmente', 'Los permisos actuales impiden continuar. El historial se conserva.', wait: true);
        }
        if ($r && in_array($r['status'], ['requested', 'held', 'confirmed'], true)) {
            $reservationRef = ['type' => 'reservation', 'id' => $r['id']];
            if ($r['status'] === 'held') {
                if ($client && in_array('commit', $r['actions'], true)) {
                    return $make('confirm_reservation', 'confirmation', 'Confirma tu contratación', 'El prestador aceptó el horario. Confírmalo antes de que termine el plazo; todavía no es una reserva definitiva.', $reservationRef);
                }

                return $make('wait_client_confirmation', 'confirmation', $client ? 'El horario ya comenzó' : 'Espera la confirmación del cliente', $client ? 'Ya no se puede confirmar este horario. Consulta la situación de la reserva.' : 'El horario está retenido durante 15 minutos. Aún no hay contratación definitiva.', $reservationRef, true);
            }
            if ($r['status'] === 'confirmed') {
                if (in_array('complete', $r['actions'], true)) {
                    return $make('record_service', 'service', 'Registra que el servicio se realizó', 'El horario terminó. Registra la realización explícitamente; el reloj no la confirma por sí solo.', $reservationRef, mobile: false);
                }

                return $make('wait_service', 'service', $r['ends_at'] <= $this->iso($now) ? 'Espera el registro del servicio' : 'Tu servicio está confirmado', 'Consulta el horario y la conversación. La realización debe registrarla explícitamente el prestador.', $reservationRef, true);
            }
            if ($r['starts_at'] <= $this->iso($now)) {
                return $make($client ? 'cancel_expired_proposal' : 'reject_expired_proposal', 'schedule', 'Revisa esta propuesta vencida', 'El horario propuesto ya comenzó. Cancela o rechaza la propuesta antes de preparar otra.', $reservationRef, mobile: $client);
            }

            return $client ? $make('wait_schedule_decision', 'schedule', 'Espera la respuesta al horario', 'Tu propuesta está enviada. Todavía no hay una reserva confirmada.', $reservationRef, true)
                : $make('decide_schedule', 'schedule', 'Revisa el horario propuesto', 'Puedes aceptar o rechazar esta propuesta con las acciones de la reserva.', $reservationRef, mobile: false);
        }
        if ($inquiry->status === InquiryStatus::Completed || $closure) {
            if (! $client && $inquiry->status !== InquiryStatus::Completed) {
                return $make('close_inquiry', 'service', 'Finaliza la solicitud', 'La realización ya está registrada. Cierra operativamente esta solicitud.', mobile: false);
            }
            if ($client && $review) {
                return $make('view_review', 'service', $review->status->value === 'withdrawn' ? 'Consulta tu reseña retirada' : 'Consulta tu reseña publicada', 'Tu registro privado se conserva junto al servicio realizado.', ['type' => 'review', 'id' => $review->id], mobile: false);
            }
            if ($client && $reviewEligible) {
                return $make('write_review', 'service', 'Comparte tu experiencia', 'El servicio está registrado como realizado. Puedes dejar una reseña conforme a las reglas vigentes.', ['type' => 'reservation', 'id' => $reviewReservation], mobile: false);
            }

            return $make('read_history', 'service', 'Consulta el cierre y su historial', 'Se conservan las decisiones, la conversación y los horarios del servicio.');
        }
        if ($commercial && ($quote['state'] ?? null) !== 'accepted') {
            if (! $client) {
                return $make('wait_quote_acceptance', 'agreement', 'Espera el acuerdo del cliente', 'El cliente debe consultar y aceptar su cotización antes de proponer un horario.', wait: true);
            }
            if (($quote['state'] ?? null) === 'pending' && $canQuote) {
                return $make('review_quote', 'agreement', 'Revisa y acepta tu cotización', 'Comprueba el importe y su vigencia. Aceptarla no confirma todavía una reserva.', ['type' => 'quote', 'id' => $quote['id']]);
            }
            if (! $canQuote) {
                return $make('wait_service_availability', 'agreement', 'El acuerdo no está disponible', 'No se pueden emitir nuevos acuerdos mientras la publicación esté inactiva. El historial y la conversación se conservan.', wait: true);
            }

            return $make('issue_quote', 'agreement', 'Consulta una cotización vigente', 'El importe debe aceptarse explícitamente antes de proponer el horario.');
        }
        if (! $canPropose) {
            return $make('wait_availability', 'schedule', 'Espera disponibilidad publicada', 'Por ahora no se pueden preparar nuevas propuestas. Puedes consultar los detalles y conversar con el prestador.', wait: true);
        }

        return $client ? $make('propose_schedule', 'schedule', 'Propón un horario', 'Acuerda una fecha disponible. El prestador debe revisar tu propuesta.')
            : $make('wait_schedule_proposal', 'schedule', 'Espera la propuesta de horario', 'El cliente puede preparar una propuesta con la disponibilidad publicada.', wait: true);
    }

    private function amount($amount): array
    {
        return ['currency' => $amount->currency, 'total_cents' => $amount->totalCents, 'commission_cents' => $amount->commissionCents,
            'provider_cents' => $amount->providerCents, 'commission_included' => true, 'commission_basis_points' => $amount->commissionBasisPoints];
    }

    private function reservation(ServiceReservation $r, int $now): array
    {
        $hold = $r->hold;

        return ['id' => $r->id, 'status' => $r->effectiveStatus($now)->value, 'label' => $r->effectiveStatus($now)->label(), 'version' => $r->version,
            'starts_at' => $this->iso($r->starts_at_utc), 'ends_at' => $this->iso($r->ends_at_utc), 'local_date' => $r->local_date,
            'local_start' => $r->localTime(), 'local_end' => $r->localEndTime(), 'timezone' => $r->timezone, 'quote_id' => $r->quote_id,
            'explicitly_completed' => $r->status === ReservationStatus::Completed,
            'hold' => $hold ? ['state' => $hold->state === 'held' && $hold->remainingSeconds($now) === 0 ? 'expired' : $hold->state,
                'remaining_seconds' => $hold->remainingSeconds($now), 'duration_seconds' => 900, 'expires_at' => BookingClock::date($hold->expires_at_us)->toISOString()] : null];
    }

    private function iso($date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->toISOString();
    }
}
