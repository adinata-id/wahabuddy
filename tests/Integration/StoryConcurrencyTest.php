<?php

namespace Tests\Integration;

use App\Enums\SendAttemptOutcome;
use App\Enums\SendAttemptSubject;
use App\Enums\StoryStatus;
use App\Models\SendAttempt;
use App\Models\Story;
use App\Models\WahaConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real two-process concurrency proof against the shared MySQL test database
 * (spec G2 / plan Task 5): exactly one worker may claim a story, and only
 * the token owner may complete it. In-memory or per-process SQLite fakes are
 * explicitly not sufficient evidence here.
 *
 * Uses DatabaseMigrations (committed rows, no wrapping transaction) so the
 * spawned worker processes can actually see the fixtures.
 */
class StoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Environment for worker subprocesses so they hit the same database as
     * this test run (phpdotenv is immutable: real env wins over .env).
     *
     * @return array<string, string>
     */
    private function workerEnv(): array
    {
        return array_merge($_ENV, array_filter([
            'DB_CONNECTION' => env('DB_CONNECTION'),
            'DB_HOST' => env('DB_HOST'),
            'DB_PORT' => env('DB_PORT'),
            'DB_DATABASE' => env('DB_DATABASE'),
            'DB_USERNAME' => env('DB_USERNAME'),
            'DB_PASSWORD' => env('DB_PASSWORD'),
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
        ], fn ($value) => $value !== null));
    }

    private function spawnClaim(int $storyId, float $delay): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'waha:test-claim', (string) $storyId, '--delay='.$delay],
            base_path(),
            $this->workerEnv(),
            null,
            60,
        );
        $process->start();

        return $process;
    }

    private function runComplete(int $storyId, string $token): string
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'waha:test-claim', (string) $storyId, '--complete='.$token],
            base_path(),
            $this->workerEnv(),
            null,
            60,
        );
        $process->mustRun();

        return trim($process->getOutput());
    }

    public function test_only_one_of_two_parallel_workers_claims_and_sends(): void
    {
        WahaConnection::singleton()->forceFill([
            'base_url' => 'http://waha.test',
            'session' => 'default',
            'revision' => 1,
            'last_test_status' => 'working',
        ])->save();

        $story = new Story;
        $story->forceFill([
            'type' => 'text',
            'text' => 'race',
            'scheduled_at' => now()->subMinute(),
            'status' => StoryStatus::Pending,
            'attempts' => 0,
            'connection_revision' => 1,
        ]);
        $story->save();

        // Two workers race with jittered starts so their claims genuinely
        // overlap in time; the CAS must still admit exactly one.
        $workers = collect([
            $this->spawnClaim($story->id, 0.05),
            $this->spawnClaim($story->id, 0.08),
        ]);

        $outputs = $workers->map(fn (Process $process) => [
            'exit' => tap($process)->wait()->getExitCode(),
            'out' => trim($process->getOutput()),
        ]);

        $tokens = $outputs->pluck('out')->filter(fn ($out) => $out !== 'NONE')->values();

        $this->assertCount(2, $outputs->where('exit', 0)->values(), 'Both workers must exit cleanly.');
        $this->assertCount(1, $tokens, 'Exactly one worker may win the claim; got: '.$tokens->implode(','));
        $this->assertSame(1, $outputs->pluck('out')->filter(fn ($out) => $out === 'NONE')->count());

        $winnerToken = $tokens->first();

        // Winner records a started attempt before HTTP; only winner sends.
        $this->assertDatabaseHas('send_attempts', [
            'attempt_token' => $winnerToken,
            'subject_type' => SendAttemptSubject::Story->value,
            'subject_id' => $story->id,
        ]);

        $this->assertSame(1, $story->fresh()->attempts, 'Exactly one send attempt started.');
        $this->assertSame(StoryStatus::Sending, $story->fresh()->status);

        // Only the token owner can complete the row.
        $this->assertSame('COMPLETED', $this->runComplete($story->id, $winnerToken));
        $this->assertSame('REJECTED', $this->runComplete($story->id, 'forged-token'));

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Sent, $story->status);
        $this->assertSame('concurrency-msg', $story->waha_message_id);

        $attempt = SendAttempt::where('attempt_token', $winnerToken)->firstOrFail();
        $this->assertSame(SendAttemptOutcome::Sent, $attempt->outcome);
    }
}
