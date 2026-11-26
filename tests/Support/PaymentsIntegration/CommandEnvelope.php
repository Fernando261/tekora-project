<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use App\Scheduling\Holds\HoldActor;
use Tests\Support\PricingSqlite\PricingValues;

final readonly class CommandEnvelope
{
    public string $id;

    public string $fingerprint;

    public function __construct(public string $namespace, public string $scope, public string $key, public string $action, public HoldActor $actor, public array $payload)
    {
        PricingValues::uuid($key);
        if (! in_array($namespace, ['price', 'quote', 'proposal', 'hold', 'resolve', 'legacy'], true) || $scope === '' || strlen($scope) > 128 || str_contains($scope, "\0")) {
            throw new \LogicException('Invalid command scope');
        }
        $this->id = self::uuid();
        $this->fingerprint = hash('sha256', self::json([$namespace, $scope, $action, $actor->scope(), $payload, 'b231-provisional-S0-freeze-v1']));
    }

    public static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20);
    }

    public static function stableKey(string $value): string
    {
        $hex = hash('sha256', $value);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
