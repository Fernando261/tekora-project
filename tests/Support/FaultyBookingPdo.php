<?php

namespace Tests\Support;

use PDO;
use PDOException;

class FaultyBookingPdo extends PDO
{
    public ?string $commitFault = null;

    public bool $beginFault = false;

    public bool $rollbackFault = false;

    public int $commitCalls = 0;

    public function beginTransaction(): bool
    {
        $result = parent::beginTransaction();
        if ($this->beginFault) {
            throw new PDOException('Injected failure after physical BEGIN');
        }

        return $result;
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);
        if ($this->beginFault && str_starts_with($statement, 'BEGIN ')) {
            throw new PDOException('Injected failure after physical BEGIN');
        }

        return $result;
    }

    public function commit(): bool
    {
        $this->commitCalls++;
        if ($this->commitFault === 'before') {
            throw new PDOException('Injected uncertain COMMIT');
        }
        $result = parent::commit();
        if ($this->commitFault === 'after') {
            throw new PDOException('Injected uncertain COMMIT');
        }

        return $result;
    }

    public function rollBack(): bool
    {
        if ($this->rollbackFault) {
            throw new PDOException('Injected cleanup failure');
        }

        return parent::rollBack();
    }
}
