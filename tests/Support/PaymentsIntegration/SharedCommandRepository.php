<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

final readonly class SharedCommandRepository
{
    public function __construct(private SessionSqlExecutor $e) {}

    public function receipt(CommandEnvelope $c): ?array
    {
        $r = $this->e->query('SELECT * FROM b231_commands WHERE namespace=? AND scope=? AND intent_key=?', [$c->namespace, $c->scope, $c->key]);
        if (! $r) {
            return null;
        }
        if (! hash_equals($r[0]['fingerprint'], $c->fingerprint)) {
            throw new \LogicException('Idempotency fingerprint conflict');
        }

        return $r[0];
    }

    public function seal(array $result): void
    {
        $c = $this->e->command;
        $this->e->query('INSERT INTO b231_commands VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$c->id, $c->namespace, $c->scope, $c->key, $c->fingerprint, $c->action, $result['outcome'] ?? 'success', CommandEnvelope::json($result), $this->e->at, $c->actor->kind, $c->actor->userId]);
    }
}
