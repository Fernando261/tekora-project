<?php

namespace Tests\MySqlV81;

use App\Enums\RoleName;
use App\Exceptions\AvailabilityFailure;
use App\Models\ProviderProfile;
use App\Services\ProviderAvailability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class AvailabilityTest extends LabTestCase
{
    public function test_public_availability_uses_civil_dates_and_enforces_permissions_and_input(): void
    {
        $provider = $this->account(RoleName::Provider);
        $profile = $this->service($provider)->providerProfile;
        $availability = app(ProviderAvailability::class);
        $input = $this->schedule(0, 'America/Los_Angeles', 9, '2029-12-31');
        $input['blocked_dates'][] = '2029-12-30';
        $input['blocked_dates'][] = '2032-02-29';
        $input['intervals'][] = ['day_of_week' => 1, 'start' => '08:00', 'end' => '09:00'];
        $this->assertSame(1, $availability->save($provider, $input)->revision);
        $this->travelTo(now('UTC')->setDate(2030, 1, 1)->setTime(2, 0));
        $public = $availability->publicFor($profile);
        $this->assertSame('America/Los_Angeles', $public['timezone']);
        $this->assertSame([480, 540], $public['days'][1]->pluck('start_minute')->all());
        $this->assertSame(['2029-12-31', '2032-02-29'], $public['blocked_dates']->all());

        try {
            $availability->save($this->account(RoleName::Client), $input);
            $this->fail('A client cannot edit provider availability.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(0, $exception->getCode());
        }
        $other = $this->account(RoleName::Provider);
        $otherProfile = $this->service($other)->providerProfile;
        $before = $this->snapshot($profile);
        $invalidInputs = [
            'provider_profile_id' => $input + ['provider_profile_id' => $profile->id],
            'timezone' => ['timezone' => 'Mars/Olympus'] + $input,
            'intervals.1.start' => ['intervals' => [
                ['day_of_week' => 1, 'start' => '09:00', 'end' => '10:00'],
                ['day_of_week' => 1, 'start' => '09:30', 'end' => '11:00'],
            ]] + $input,
        ];
        foreach ($invalidInputs as $field => $invalid) {
            try {
                $availability->save($other, $invalid);
                $this->fail("Expected invalid field {$field} to be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertNull($otherProfile->availabilitySetting()->first());
        $this->assertSame($before, $this->snapshot($profile));
    }

    public function test_innodb_enforces_exclusion_ranges_and_civil_dates_for_direct_writes(): void
    {
        $profile = $this->service($this->account(RoleName::Provider))->providerProfile;
        $first = $this->slot($profile->id, 0, 60);
        $firstId = DB::table('provider_weekly_intervals')->insertGetId($first);
        $adjacent = $this->slot($profile->id, 60, 1439);
        DB::table('provider_weekly_intervals')->insert($adjacent);
        DB::table('provider_weekly_intervals')->where('id', $firstId)->update(['end_minute' => 59]);
        $this->assertMySqlError(1644, fn () => DB::table('provider_weekly_intervals')->insert($this->slot($profile->id, 55, 70)));
        $this->assertMySqlError(1644, fn () => DB::table('provider_weekly_intervals')->where('id', $firstId)->update(['end_minute' => 61]));
        $this->assertMySqlError(1644, fn () => DB::table('provider_weekly_intervals')->insert([
            ['day_of_week' => 2] + $this->slot($profile->id, 0, 60),
            ['day_of_week' => 2] + $this->slot($profile->id, 59, 90),
        ]));
        foreach ([['day_of_week' => 8], ['end_minute' => 1440], ['start_minute' => 61]] as $invalid) {
            $this->assertMySqlError(3819, fn () => DB::table('provider_weekly_intervals')->insert($invalid + ['day_of_week' => 2] + $first));
        }
        $this->assertMySqlError(1452, fn () => DB::table('provider_weekly_intervals')->insert($this->slot(PHP_INT_MAX, 0, 60)));
        $this->assertSame(2, $profile->weeklyIntervals()->count());

        $settings = ['provider_profile_id' => $profile->id, 'revision' => 1, 'timezone' => 'Etc/UTC',
            'created_at' => '2030-01-01 00:00:00', 'updated_at' => '2030-01-01 00:00:00'];
        $this->assertMySqlError(3819, fn () => DB::table('provider_availability_settings')->insert(['revision' => 0] + $settings));
        $this->assertMySqlError(3819, fn () => DB::table('provider_availability_settings')->insert(['timezone' => ''] + $settings));

        $date = ['provider_profile_id' => $profile->id, 'blocked_date' => '2032-02-29'];
        DB::table('provider_blocked_dates')->insert($date);
        $this->assertMySqlError(1062, fn () => DB::table('provider_blocked_dates')->insert($date));
        $this->assertMySqlError(3819, fn () => DB::table('provider_blocked_dates')->insert(['blocked_date' => '2101-01-01'] + $date));
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            DB::statement('SET SESSION sql_mode = ?', [$mode.',ALLOW_INVALID_DATES']);
            $this->assertMySqlError(3819, fn () => DB::table('provider_blocked_dates')->insert(['blocked_date' => '2031-02-29'] + $date));
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        $this->assertSame(['2032-02-29'], $profile->blockedDates()->pluck('blocked_date')->all());
    }

    public function test_failed_replacement_rolls_back_zone_revision_intervals_and_dates(): void
    {
        $provider = $this->account(RoleName::Provider);
        $profile = $this->service($provider)->providerProfile;
        $availability = app(ProviderAvailability::class);
        $availability->save($provider, $this->schedule(0, 'America/Mexico_City', 9, '2032-02-29'));
        $before = $this->snapshot($profile);
        DB::unprepared("CREATE TRIGGER v812_reject_date BEFORE INSERT ON provider_blocked_dates
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic fault'");
        try {
            $availability->save($provider, $this->schedule(1, 'Asia/Tokyo', 11, '2033-01-01'));
            $this->fail('Expected the replacement to roll back.');
        } catch (AvailabilityFailure $exception) {
            $this->assertSame(503, $exception->status);
        } finally {
            DB::unprepared('DROP TRIGGER v812_reject_date');
        }
        $this->assertSame($before, $this->snapshot($profile));
        $this->assertSame(2, $availability->save($provider, $this->schedule(1, 'Asia/Tokyo', 11, '2033-01-01'))->revision);
    }

    public static function initialRevisions(): array
    {
        return ['first write' => [0], 'replacement' => [1]];
    }

    #[DataProvider('initialRevisions')]
    public function test_two_processes_compete_for_the_same_revision(int $revision): void
    {
        $provider = $this->account(RoleName::Provider);
        $profile = $this->service($provider)->providerProfile;
        if ($revision === 1) {
            app(ProviderAvailability::class)->save($provider, $this->schedule(0, 'America/Mexico_City', 8, '2030-01-01'));
        }
        $inputs = [$this->schedule($revision, 'Asia/Tokyo', 9, '2032-02-29'), $this->schedule($revision, 'Etc/UTC', 11, '2033-01-01')];
        $workers = [];
        try {
            foreach ($inputs as $input) {
                $workers[] = $this->startAvailabilityWorker('save', $provider->id, $input);
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
            }
            $results = array_map($this->workerResult(...), $workers);
            $saved = array_filter($results, fn (array $result) => isset($result['revision']));
            $this->assertCount(1, $saved);
            $winner = array_key_first($saved);
            $this->assertSame(['revision' => $revision + 1, 'timezone' => $inputs[$winner]['timezone']], $results[$winner]);
            $this->assertSame(['status' => 409], $results[1 - $winner]);
            $this->assertSame($revision + 1, $profile->availabilitySetting()->sole()->revision);
            $this->assertSame($inputs[$winner]['timezone'], $profile->availabilitySetting()->sole()->timezone);
            $this->assertSame($inputs[$winner]['blocked_dates'], $profile->blockedDates()->pluck('blocked_date')->all());
            $this->assertSame($winner === 0 ? 540 : 660, (int) $profile->weeklyIntervals()->sole()->start_minute);
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    public function test_direct_writer_waits_then_detects_overlap_using_a_current_read(): void
    {
        $profile = $this->service($this->account(RoleName::Provider))->providerProfile;
        $worker = $this->startAvailabilityWorker('insert', $profile->id, $this->slot($profile->id, 100, 150));
        try {
            DB::transaction(function () use ($profile, $worker) {
                DB::table('provider_weekly_intervals')->insert($this->slot($profile->id, 60, 120));
                fwrite($worker[1][0], "go\n");
                $this->assertSame(['code' => 1205, 'initial' => 0, 'visible' => 0], $this->workerResult($worker));
            });
            fwrite($worker[1][0], "retry\n");
            $this->assertSame(['code' => 1644, 'initial' => 0, 'visible' => 0], $this->workerResult($worker));
            $this->assertSame(60, (int) $profile->weeklyIntervals()->sole()->start_minute);
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_read_committed_public_reader_fails_closed_during_a_replacement(): void
    {
        $provider = $this->account(RoleName::Provider);
        $profile = $this->service($provider)->providerProfile;
        $availability = app(ProviderAvailability::class);
        $availability->save($provider, $this->schedule(0, 'America/Mexico_City', 9, '2090-01-01'));
        $worker = $this->startAvailabilityWorker('read', $profile->id);
        try {
            DB::transaction(function () use ($provider, $availability, $worker) {
                $availability->save($provider, $this->schedule(1, 'Asia/Tokyo', 11, '2090-01-02'));
                fwrite($worker[1][0], "go\n");
                $this->assertSame(['unavailable' => true], $this->workerResult($worker));
            });
            fwrite($worker[1][0], "retry\n");
            $result = $this->workerResult($worker);
            $this->assertSame('Asia/Tokyo', $result['timezone']);
            $this->assertSame(['2090-01-02'], $result['blocked_dates']);
            $this->assertSame(660, $result['days'][1][0]['start_minute']);
        } finally {
            $this->closeWorker($worker);
        }
    }

    private function startAvailabilityWorker(string $mode, int $id, array $input = []): array
    {
        return $this->startWorker('availability-worker.php', $mode, $id, json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function schedule(int $revision, string $timezone, int $hour, string $date): array
    {
        return ['revision' => $revision, 'timezone' => $timezone, 'blocked_dates' => [$date],
            'intervals' => [['day_of_week' => 1, 'start' => sprintf('%02d:00', $hour), 'end' => sprintf('%02d:00', $hour + 1)]]];
    }

    private function slot(int $profile, int $start, int $end): array
    {
        return ['provider_profile_id' => $profile, 'day_of_week' => 1, 'start_minute' => $start, 'end_minute' => $end];
    }

    private function snapshot(ProviderProfile $profile): array
    {
        return [$profile->availabilitySetting()->firstOrFail()->getAttributes(),
            $profile->weeklyIntervals()->orderBy('id')->get()->toArray(), $profile->blockedDates()->orderBy('id')->get()->toArray()];
    }
}
