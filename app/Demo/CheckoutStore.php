<?php

namespace App\Demo;

use App\Http\Resources\Api\V1\QuoteResource;
use App\Payments\CommercialAmount;
use App\Payments\PriceVersion;
use App\Payments\QuoteAcceptance;
use App\Payments\QuoteSnapshot;
use App\Payments\SyntheticQuote;
use App\Support\BookingClock;
use Illuminate\Support\Str;
use RuntimeException;

/** Private presentation records only. Never a PricingStore, payment ledger or booking command. */
final class CheckoutStore
{
    private const KIND = 'tekora-checkout-demo-v1';

    public static function prepare(string $path): string
    {
        abort_unless(app()->environment(['local', 'testing']) && ! app()->configurationIsCached(), 404);
        $parent = realpath(dirname($path));
        if (! $parent || file_exists($path) || is_link($path) || basename($path) === '..'
            || $path !== $parent.DIRECTORY_SEPARATOR.basename($path)) {
            throw new RuntimeException('Use una ruta absoluta nueva dentro de un directorio existente y canónico.');
        }
        if (! mkdir($path, 0700) || ! mkdir($path.'/contexts', 0700)) {
            throw new RuntimeException('No se pudo preparar la demo.');
        }
        $token = bin2hex(random_bytes(32));
        $file = fopen($path.'/runtime.json', 'x');
        if (! $file) {
            throw new RuntimeException('No se pudo crear la custodia demo.');
        }
        try {
            chmod($path.'/runtime.json', 0600);
            $json = json_encode(['kind' => self::KIND, 'operator_hash' => hash('sha256', $token)], JSON_THROW_ON_ERROR);
            if (fwrite($file, $json) !== strlen($json) || ! fflush($file)) {
                throw new RuntimeException('Preparación incompleta.');
            }
        } finally {
            fclose($file);
        }

        return $token;
    }

    private function root(): string
    {
        abort_unless(app()->environment(['local', 'testing']) && ! app()->configurationIsCached(), 404);
        $path = config('checkout_demo.root');
        abort_unless(is_string($path) && realpath($path) === $path && ! is_link($path)
            && is_dir($path.'/contexts') && is_readable($path.'/contexts') && is_writable($path.'/contexts')
            && ! is_link($path.'/contexts') && is_file($path.'/runtime.json') && ! is_link($path.'/runtime.json'), 503, 'La demo no está preparada.');
        if (PHP_OS_FAMILY !== 'Windows') {
            abort_unless((fileperms($path) & 0777) === 0700 && (fileperms($path.'/contexts') & 0777) === 0700, 503);
        }
        $runtime = json_decode(file_get_contents($path.'/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($runtime['kind'] ?? null) === self::KIND && is_string($runtime['operator_hash'] ?? null), 503);

        return $path;
    }

    public function operator(string $token): bool
    {
        $data = json_decode(file_get_contents($this->root().'/runtime.json'), true, flags: JSON_THROW_ON_ERROR);

        return preg_match('/^[a-f0-9]{64}$/D', $token) && hash_equals($data['operator_hash'], hash('sha256', $token));
    }

    public function create(): array
    {
        $root = $this->root();
        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        $now = now('UTC')->toDateTimeImmutable();
        $amount = CommercialAmount::fromCents(100000);
        // Reuse B3 value objects/resources, never issue or accept a database quote.
        $price = new PriceVersion((string) Str::uuid(), 1, 2, 1, $amount, 2, (string) Str::uuid(), $now);
        $quote = new SyntheticQuote((string) Str::uuid(), 1, 1, 2, 1, $price, (string) Str::uuid(), $now, $now->modify('+30 minutes'));
        $snapshot = new QuoteSnapshot($quote, new QuoteAcceptance($quote->id, 1, $now), $now);
        $state = ['kind' => self::KIND, 'id' => $id, 'client_hash' => hash('sha256', $token), 'csrf' => bin2hex(random_bytes(32)),
            'accepted_us' => BookingClock::micros(), 'observed_us' => BookingClock::micros(),
            'quote' => json_decode(json_encode(new QuoteResource($snapshot), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            'service' => 'Una sesión para impulsar tu negocio', 'provider' => 'Nexo Estudio · Prestador demo',
            'starts_at' => now('America/Mexico_City')->addDays(7)->setTime(13, 0)->toISOString(), 'attempts' => []];
        $file = fopen($root.'/contexts/'.$id.'.json', 'x+b');
        abort_unless($file, 503);
        try {
            chmod($root.'/contexts/'.$id.'.json', 0600);
            $this->write($file, $state);
        } finally {
            fclose($file);
        }

        return [$id.':'.$token, $this->view($state)];
    }

    public function client(string $credential, callable $operation): mixed
    {
        $parts = explode(':', $credential);
        abort_unless(count($parts) === 2 && preg_match('/^[a-f0-9]{64}$/D', $parts[1]), 401);

        return $this->locked($parts[0], $operation, $parts[1]);
    }

    public function receiver(string $id, callable $operation): mixed
    {
        // Only called after the independent operator capability and CSRF checks.
        return $this->locked($id, $operation);
    }

    private function locked(string $id, callable $operation, ?string $clientToken = null): mixed
    {
        abort_unless(Str::isUuid($id), 404);
        $path = $this->root().'/contexts/'.$id.'.json';
        abort_unless(is_file($path) && ! is_link($path), 404);
        $file = fopen($path, 'r+b');
        abort_unless($file, 503);
        try {
            abort_unless(flock($file, LOCK_EX), 503);
            $state = json_decode(stream_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(($state['kind'] ?? null) === self::KIND && ($state['id'] ?? null) === $id, 503);
            if ($clientToken !== null) {
                abort_unless(hash_equals($state['client_hash'], hash('sha256', $clientToken)), 404);
            }
            $state['observed_us'] = max($state['observed_us'], BookingClock::micros());
            // Keep the observed clock even when admission fails; rollback of wall time
            // cannot revive an expired synthetic hold. No business rows are involved.
            $this->write($file, $state);
            $result = $operation($state);
            $this->write($file, $state);

            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private function write($file, array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        abort_unless(rewind($file) && fwrite($file, $json) === strlen($json) && ftruncate($file, strlen($json)) && fflush($file), 503);
    }

    private function live(array $state): void
    {
        abort_if($state['observed_us'] >= $state['accepted_us'] + BookingClock::HOLD_MICROSECONDS, 409, 'demo_hold_expired');
    }

    public function submit(array &$state, array $input): array
    {
        $key = strtolower($input['idempotency_key']);
        $fingerprint = hash('sha256', json_encode([$input['method'], $input['instrument'] ?? null, $input['scenario']], JSON_THROW_ON_ERROR));
        foreach ($state['attempts'] as $attempt) {
            if ($attempt['key'] === $key) {
                abort_unless(hash_equals($attempt['fingerprint'], $fingerprint), 409, 'demo_key_conflict');

                return $this->view($state, $attempt['id']);
            }
        }
        $this->live($state);
        abort_if(count($state['attempts']) >= 10, 429, 'demo_attempt_limit');
        // A context has only one successful/pending intention, even with different keys.
        foreach ($state['attempts'] as $attempt) {
            abort_if(in_array($attempt['status'], ['demo_completed', 'demo_awaiting_cash', 'demo_temporary_error'], true), 409, 'demo_existing_intention');
        }
        $attempt = ['id' => (string) Str::uuid(), 'key' => $key, 'fingerprint' => $fingerprint,
            'method' => $input['method'], 'instrument' => $input['instrument'] ?? null, 'scenario' => $input['scenario'],
            'status' => match ($input['scenario']) {
                'declined' => 'demo_declined', 'temporary' => 'demo_temporary_error', default => $input['method'] === 'cash' ? 'demo_awaiting_cash' : 'demo_completed'
            },
            'created_at' => BookingClock::date($state['observed_us'])->toISOString(), 'receipt' => null];
        $this->receipt($state, $attempt);
        $state['attempts'][] = $attempt;

        return $this->view($state, $attempt['id']);
    }

    public function retry(array &$state, string $id): array
    {
        foreach ($state['attempts'] as &$attempt) {
            if ($attempt['id'] !== $id) {
                continue;
            }
            if ($attempt['status'] === 'demo_temporary_error') {
                $this->live($state);
                $attempt['status'] = $attempt['method'] === 'cash' ? 'demo_awaiting_cash' : 'demo_completed';
                $this->receipt($state, $attempt);
            }

            return $this->view($state, $id);
        }
        abort(404);
    }

    public function receive(array &$state, string $id): array
    {
        foreach ($state['attempts'] as &$attempt) {
            if ($attempt['id'] !== $id) {
                continue;
            }
            if ($attempt['status'] === 'demo_completed' && $attempt['method'] === 'cash') {
                return $this->view($state, $id);
            }
            $this->live($state);
            abort_unless($attempt['method'] === 'cash' && $attempt['status'] === 'demo_awaiting_cash', 409);
            $attempt['status'] = 'demo_completed';
            $this->receipt($state, $attempt);

            return $this->view($state, $id);
        }
        abort(404);
    }

    private function receipt(array $state, array &$attempt): void
    {
        if ($attempt['status'] !== 'demo_completed' || $attempt['receipt'] !== null) {
            return;
        }
        $attempt['receipt'] = ['reference' => 'TK-DEMO-'.strtoupper(substr(hash('sha256', $state['id'].$attempt['id']), 0, 12)),
            'demo_only' => true, 'external_charge' => false, 'method' => $attempt['method'], 'amount' => $state['quote']['amount'],
            'recorded_at' => BookingClock::date($state['observed_us'])->toISOString(), 'recorded_by' => $attempt['method'] === 'cash' ? 'provider_demo' : 'demo_engine'];
    }

    public function view(array $state, ?string $attemptId = null): array
    {
        $attempt = null;
        foreach ($state['attempts'] as $item) {
            if ($attemptId === null || $item['id'] === $attemptId) {
                $attempt = $item;
            }
        }
        if ($attemptId !== null) {
            abort_unless($attempt, 404);
        }
        if ($attempt) {
            unset($attempt['key'], $attempt['fingerprint']);
            if ($attempt['status'] === 'demo_awaiting_cash' && $state['observed_us'] >= $state['accepted_us'] + BookingClock::HOLD_MICROSECONDS) {
                $attempt['status'] = 'demo_expired';
            }
        }

        return ['scope' => 'isolated_demo', 'id' => $state['id'], 'csrf' => $state['csrf'], 'service' => $state['service'], 'provider' => $state['provider'],
            'starts_at' => $state['starts_at'], 'quote' => $state['quote'], 'attempt' => $attempt,
            'hold' => ['synthetic' => true, 'duration_seconds' => 900, 'expires_at' => BookingClock::date($state['accepted_us'] + BookingClock::HOLD_MICROSECONDS)->toISOString(),
                'remaining_seconds' => max(0, intdiv(max(0, $state['accepted_us'] + BookingClock::HOLD_MICROSECONDS - $state['observed_us']) + 999999, 1000000))],
            'server_now' => BookingClock::date($state['observed_us'])->toISOString(), 'real_payments_enabled' => false, 'reservation_changed' => false];
    }

    public function pending(): array
    {
        $rows = [];
        foreach (glob($this->root().'/contexts/*.json') as $path) {
            $id = basename($path, '.json');
            $row = $this->receiver($id, fn (&$state) => $this->view($state));
            unset($row['csrf']);
            if (($row['attempt']['status'] ?? null) === 'demo_awaiting_cash') {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
