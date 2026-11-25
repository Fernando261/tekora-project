<?php

declare(strict_types=1);

namespace App\Payments;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LogicException;
use PDO;

trait PersistsPricing
{
    private function normalizePricingRow(array $row): array
    {
        return $row;
    }

    private function row(string $sql, array $values): ?array
    {
        $statement = $this->connection()->prepare($sql);
        try {
            $statement->execute($values);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $this->normalizePricingRow($row);
        } finally {
            $statement->closeCursor();
        }
    }

    private function insert(string $table, array $values): void
    {
        if (! $this->active) {
            throw new LogicException('La escritura requiere propiedad transaccional.');
        }
        $statement = $this->connection()->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
        try {
            foreach (array_values($values) as $i => $value) {
                $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();
        } finally {
            $statement->closeCursor();
        }
    }

    public static function timestamp(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function price(array $row): PriceVersion
    {
        return new PriceVersion($row['id'], (int) $row['service_id'], (int) $row['provider_profile_id'], (int) $row['version'], CommercialAmount::fromCents((int) $row['total_cents']), (int) $row['created_by'], $row['idempotency_key'], new DateTimeImmutable($row['created_at_utc'], new DateTimeZone('UTC')));
    }

    private function amounts(CommercialAmount $amount): array
    {
        return ['total_cents' => $amount->totalCents, 'commission_cents' => $amount->commissionCents, 'provider_cents' => $amount->providerCents, 'currency' => $amount->currency, 'duration_minutes' => $amount->durationMinutes, 'policy_version' => $amount->policyVersion, 'commission_basis_points' => $amount->commissionBasisPoints, 'provider_basis_points' => $amount->providerBasisPoints];
    }

    public function latestPrice(int $serviceId): ?PriceVersion
    {
        $row = $this->row('SELECT * FROM service_price_versions WHERE service_id=? ORDER BY version DESC LIMIT 1', [$serviceId]);

        return $row ? $this->price($row) : null;
    }

    public function priceByKey(int $serviceId, string $key): ?PriceVersion
    {
        $row = $this->row('SELECT * FROM service_price_versions WHERE service_id=? AND idempotency_key=?', [$serviceId, strtolower($key)]);

        return $row ? $this->price($row) : null;
    }

    public function appendPrice(PriceVersion $price): void
    {
        $this->insert('service_price_versions', ['id' => $price->id, 'service_id' => $price->serviceId, 'provider_profile_id' => $price->providerProfileId, 'version' => $price->version, 'created_by' => $price->createdBy, 'idempotency_key' => $price->idempotencyKey, 'created_at_utc' => self::timestamp($price->createdAt)] + $this->amounts($price->amount));
    }

    public function quote(string $id): ?SyntheticQuote
    {
        $row = $this->row('SELECT * FROM service_quotes WHERE id=?', [$id]);
        if (! $row) {
            return null;
        }
        $price = $this->price($this->row('SELECT * FROM service_price_versions WHERE id=?', [$row['price_version_id']]) ?? throw new LogicException('Falta el precio histórico.'));
        foreach ($this->amounts($price->amount) as $field => $value) {
            if ($row[$field] !== $value) {
                throw new LogicException('Snapshot monetario inconsistente.');
            }
        }

        return new SyntheticQuote($row['id'], (int) $row['service_inquiry_id'], (int) $row['service_id'], (int) $row['provider_profile_id'], (int) $row['client_user_id'], $price, $row['idempotency_key'], new DateTimeImmutable($row['issued_at_utc'], new DateTimeZone('UTC')), new DateTimeImmutable($row['expires_at_utc'], new DateTimeZone('UTC')));
    }

    public function quoteByKey(int $clientId, string $key): ?SyntheticQuote
    {
        $row = $this->row('SELECT id FROM service_quotes WHERE client_user_id=? AND idempotency_key=?', [$clientId, strtolower($key)]);

        return $row ? $this->quote($row['id']) : null;
    }

    public function appendQuote(SyntheticQuote $quote): void
    {
        $this->insert('service_quotes', ['id' => $quote->id, 'service_inquiry_id' => $quote->inquiryId, 'service_id' => $quote->serviceId, 'provider_profile_id' => $quote->providerProfileId, 'client_user_id' => $quote->clientId, 'price_version_id' => $quote->price->id, 'price_version' => $quote->price->version, 'idempotency_key' => $quote->idempotencyKey, 'issued_at_utc' => self::timestamp($quote->issuedAt), 'expires_at_utc' => self::timestamp($quote->expiresAt)] + $this->amounts($quote->price->amount));
    }

    public function acceptance(string $quoteId): ?QuoteAcceptance
    {
        $row = $this->row('SELECT * FROM quote_acceptances WHERE quote_id=?', [$quoteId]);

        return $row ? new QuoteAcceptance($row['quote_id'], (int) $row['client_user_id'], new DateTimeImmutable($row['accepted_at_utc'], new DateTimeZone('UTC'))) : null;
    }

    public function appendAcceptance(QuoteAcceptance $acceptance): void
    {
        $this->insert('quote_acceptances', ['quote_id' => $acceptance->quoteId, 'client_user_id' => $acceptance->clientId, 'accepted_at_utc' => self::timestamp($acceptance->acceptedAt)]);
    }
}
