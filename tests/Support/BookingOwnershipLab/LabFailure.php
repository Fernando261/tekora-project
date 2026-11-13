<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use JsonSerializable;
use PDOException;
use RuntimeException;
use Throwable;

final class LabFailure extends RuntimeException implements JsonSerializable
{
    public ?RunResult $result = null;

    public function __construct(public readonly string $reason, public readonly string $phase, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }

    public static function from(Throwable $error, string $phase, string $reason = 'OperationFailure'): self
    {
        return $error instanceof self ? $error : new self($reason, $phase, $error);
    }

    public function pdoCause(): ?PDOException
    {
        for ($error = $this->getPrevious(); $error; $error = $error->getPrevious()) {
            if ($error instanceof PDOException) {
                return $error;
            }
        }

        return null;
    }

    public static function sqliteCode(Throwable $error): ?int
    {
        for ($e = $error; $e; $e = $e->getPrevious()) {
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && is_int($e->errorInfo[1])) {
                return $e->errorInfo[1];
            }
        }

        return null;
    }

    public static function isForeignBegin(Throwable $error): bool
    {
        for ($e = $error; $e; $e = $e->getPrevious()) {
            if ($e instanceof PDOException) {
                return ($e->errorInfo[0] ?? null) === 'HY000'
                    && ($e->errorInfo[1] ?? null) === 1
                    && ($e->errorInfo[2] ?? null) === 'cannot start a transaction within a transaction';
            }
        }

        return false;
    }

    public function jsonSerialize(): array
    {
        return ['reason' => $this->reason, 'phase' => $this->phase, 'sqlite_code' => self::sqliteCode($this)];
    }
}
