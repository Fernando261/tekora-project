<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldReceipt;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SlotHold;
use App\Scheduling\Holds\UtcInterval;
use DateTimeImmutable;
use LogicException;

final class HoldValues
{
    public const MIN_US = 946684800000000;

    public const MAX_US = 4133980799999999;

    public static function uuid(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value)) {
            throw new LogicException('Noncanonical UUID.');
        }

        return $value;
    }

    public static function positive(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw new LogicException('A positive stored integer is required.');
        }

        return $value;
    }

    public static function micros(DateTimeImmutable $value): int
    {
        $seconds = $value->getTimestamp();
        if ($seconds < 946684800 || $seconds > 4133980799) {
            throw new LogicException('Unsupported synthetic UTC range.');
        }

        return $seconds * 1000000 + (int) $value->format('u');
    }

    public static function instant(mixed $value): DateTimeImmutable
    {
        if (! is_int($value) || $value < self::MIN_US || $value > self::MAX_US) {
            throw new LogicException('Invalid stored UTC microseconds.');
        }
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($value, 1000000), $value % 1000000));
        if (! $date || self::micros($date) !== $value) {
            throw new LogicException('UTC conversion mismatch.');
        }

        return $date;
    }

    public static function scope(string $scope): array
    {
        if (! preg_match('/\A((?:provider|client):[1-9][0-9]*|system:system):([0-9a-f-]{36})\z/', $scope, $match)) {
            throw new LogicException('Invalid receipt scope.');
        }
        self::uuid($match[2]);
        if ($match[1] !== 'system:system') {
            $id = explode(':', $match[1])[1];
            if (strlen($id) > strlen((string) PHP_INT_MAX) || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
                throw new LogicException('Actor integer overflow.');
            }
        }

        return [$match[1], $match[2]];
    }

    public static function row(SlotHold $hold, HoldEvent $event): array
    {
        SlotHold::fromStorage(self::uuid($hold->id), $hold->proposal, $hold->state, $hold->version, $hold->acceptedAt, $hold->expiresAt, $hold->updatedAt);
        if ($event->holdId !== $hold->id || $event->version !== $hold->version || $event->to !== $hold->state || $event->at != $hold->updatedAt || ! $event->authorizes($hold->proposal) || ($hold->version === 1 ? $event->from !== null : $event->from !== HoldState::Held)) {
            throw new LogicException('Invalid transition event.');
        }
        $p = $hold->proposal;
        $q = $p->quote;

        return [
            'hold_id' => $hold->id, 'reservation_id' => self::positive($p->reservationId), 'proposal_version' => self::positive($p->proposalVersion),
            'quote_ref' => $q->id, 'inquiry_id' => self::positive($q->inquiryId), 'service_id' => self::positive($q->serviceId),
            'provider_profile_id' => self::positive($q->providerProfileId), 'provider_user_id' => self::positive($q->providerUserId), 'client_user_id' => self::positive($q->clientUserId),
            'starts_at_us' => self::micros($p->interval->start), 'ends_at_us' => self::micros($p->interval->end), 'state' => $hold->state->value, 'version' => $hold->version,
            'accepted_at_us' => self::micros($hold->acceptedAt), 'expires_at_us' => self::micros($hold->expiresAt), 'updated_at_us' => self::micros($hold->updatedAt),
            'actor_kind' => $event->actor->kind, 'actor_user_id' => $event->actor->userId,
        ];
    }

    public static function actor(array $row): HoldActor
    {
        return match ($row['actor_kind']) {
            'provider' => HoldActor::provider(self::positive($row['actor_user_id'])),
            'client' => HoldActor::client(self::positive($row['actor_user_id'])),
            'system' => $row['actor_user_id'] === null ? HoldActor::system() : throw new LogicException('Invalid system actor.'),
            default => throw new LogicException('Unknown actor.'),
        };
    }

    public static function event(array $row): HoldEvent
    {
        $actor = self::actor($row);
        if ($row['actor_scope'] !== $actor->scope()) {
            throw new LogicException('Stored actor scope mismatch.');
        }

        return new HoldEvent(self::uuid($row['hold_id']), self::positive($row['version']), $row['from_state'] === null ? null : HoldState::from($row['from_state']), HoldState::from($row['to_state']), $actor, self::instant($row['occurred_at_us']));
    }

    public static function hydrate(array $row, array $events): SlotHold
    {
        $q = new AcceptedQuoteReference($row['quote_ref'], self::positive($row['inquiry_id']), self::positive($row['service_id']), self::positive($row['provider_profile_id']), self::positive($row['provider_user_id']), self::positive($row['client_user_id']));
        $proposal = new ReservationProposal(self::positive($row['reservation_id']), self::positive($row['proposal_version']), new UtcInterval(self::instant($row['starts_at_us']), self::instant($row['ends_at_us'])), $q);
        $hold = SlotHold::fromStorage(self::uuid($row['hold_id']), $proposal, HoldState::from($row['state']), self::positive($row['version']), self::instant($row['accepted_at_us']), self::instant($row['expires_at_us']), self::instant($row['updated_at_us']));
        if (count($events) !== $hold->version) {
            throw new LogicException('Incomplete hold history.');
        }
        foreach ($events as $index => $event) {
            $initial = $index === 0;
            if ($event->holdId !== $hold->id || $event->version !== $index + 1 || $event->from !== ($initial ? null : HoldState::Held) || $event->to !== ($initial ? HoldState::Held : $hold->state) || $event->at != ($initial ? $hold->acceptedAt : $hold->updatedAt) || ! $event->authorizes($proposal)) {
                throw new LogicException('Inconsistent hold history.');
            }
        }
        if (self::row($hold, $events[array_key_last($events)]) !== $row) {
            throw new LogicException('Stored hold snapshot mismatch.');
        }

        return $hold;
    }

    public static function receipt(HoldReceipt $receipt, SlotHold $hold, HoldEvent $event): array
    {
        [$actorScope, $uuid] = self::scope($receipt->scopeKey);
        $fingerprint = match ($event->to) {
            HoldState::Held => hash('sha256', 'accept:'.$hold->proposal->fingerprint()),
            HoldState::Committed, HoldState::Released => hash('sha256', $event->to->value.':'.$hold->id.':'.($event->version - 1)),
            default => throw new LogicException('Expiry is not a successful command receipt.'),
        };
        if ($receipt->holdId !== $hold->id || $event->holdId !== $hold->id || $event->actor->scope() !== $actorScope || ! hash_equals($fingerprint, $receipt->fingerprint)) {
            throw new LogicException('Receipt does not match its command/event.');
        }

        return ['scope_key' => $receipt->scopeKey, 'actor_scope' => $actorScope, 'intention_uuid' => $uuid, 'fingerprint' => $fingerprint, 'hold_id' => $hold->id, 'event_version' => $event->version, 'recorded_at_us' => self::micros($event->at)];
    }
}
