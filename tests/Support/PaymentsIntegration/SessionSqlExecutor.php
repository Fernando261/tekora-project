<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use Closure;

/** Internal only: never delivered on WriteSession. SQL comes from trusted port implementations. */
final readonly class SessionSqlExecutor
{
    public function __construct(private Closure $execute, public SessionLease $lease, public CommandEnvelope $command, public int $at) {}

    public function query(string $sql, array $params = []): array
    {
        return ($this->execute)($this->lease, $sql, $params);
    }

    public function insert(string $table, array $row): void
    {
        // All table/column names below are hard-coded by repositories, never command input.
        if (! preg_match('/\Ab231_[a-z_]+\z/', $table)) {
            throw new \LogicException('Invalid internal table');
        }
        foreach (array_keys($row) as $key) {
            if (! preg_match('/\A[a-z_]+\z/', $key)) {
                throw new \LogicException('Invalid column');
            }
        }
        $row['command_id'] = $this->command->id;
        $this->query('INSERT INTO '.$table.'('.implode(',', array_keys($row)).') VALUES('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
    }

    public function diagnostic(): array
    {
        return $this->query('SELECT :epoch AS epoch', ['epoch' => $this->lease->epoch]);
    }
}
