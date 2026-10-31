<?php

namespace Tests\Integration;

use App\Enums\BroadcastStatus;
use App\Enums\BroadcastType;
use App\Enums\RecipientStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\WahaConnection;
use App\Services\Sending\SessionSendGate;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two-process proof on the shared MySQL database (plan Task 7): a single
 * recipient can only ever be claimed by one worker, and the session pacing
 * stamp survives the race so no second send slips through early.
 */
class BroadcastConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /**
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
            'CACHE_STORE' => 'database',
        ], fn ($value) => $value !== null));
    }

    private function spawn(string ...$args): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'waha:test-claim-recipient', ...$args],
            base_path(),
            $this->workerEnv(),
            null,
            60,
        );
        $process->start();

        return $process;
    }

    public function test_two_workers_claim_one_recipient_once_and_pacing_holds(): void
    {
        // The shared session pacing must live in a store both processes see;
        // the test env uses the database cache so the array driver never
        // fakes cross-process visibility.
        config(['cache.default' => 'database']);
        $this->app['cache']->forgetDriver('database');

        WahaConnection::singleton()->forceFill([
            'base_url' => 'http://waha.test',
            'session' => 'default',
            'revision' => 1,
            'last_test_status' => 'working',
        ])->save();

        $broadcast = new Broadcast;
        $broadcast->forceFill([
            'name' => 'race',
            'type' => BroadcastType::Text,
            'template_text' => 'Hi!',
            'status' => BroadcastStatus::Running,
            'connection_revision' => 1,
            'batch_count' => 0,
            'next_send_at' => now()->subSecond(),
        ]);
        $broadcast->save();

        $broadcast->recipients()->create([
            'chat_id' => '628111111111@c.us',
            'rendered_text' => 'Hi!',
            'status' => RecipientStatus::Pending,
        ]);

        $workers = collect([
            $this->spawn((string) $broadcast->id, '--delay=0.05'),
            $this->spawn((string) $broadcast->id, '--delay=0.08'),
        ]);

        $outputs = $workers->map(function (Process $process) {
            $process->wait();

            return trim($process->getOutput());
        });

        $wins = $outputs->filter(fn ($out) => $out !== 'NONE')->values();
        $losses = $outputs->filter(fn ($out) => $out === 'NONE');

        $this->assertCount(1, $wins, 'Exactly one worker may claim the recipient; got: '.$wins->implode(','));
        $this->assertCount(1, $losses);

        [$token, $recipientId] = explode(':', $wins->first(), 2);

        $recipient = BroadcastRecipient::findOrFail($recipientId);
        $this->assertSame(RecipientStatus::Sending, $recipient->status);
        $this->assertSame(1, $recipient->attempts);

        // The winner's pacing write moved the campaign and session forward:
        // a follow-up claim attempt must NOT fire early.
        $this->assertTrue($broadcast->fresh()->next_send_at->isFuture());

        $gate = app(SessionSendGate::class);
        $next = $gate->nextAllowedAt(app(ConnectionRepository::class)->snapshot());
        $this->assertNotNull($next);
        $this->assertTrue($next->isFuture());

        // Only the token owner completes; a forged token is rejected.
        $complete = fn (string $spec) => tap($this->spawn((string) $broadcast->id, '--complete='.$spec), fn ($p) => $p->wait())->getOutput();

        $this->assertSame('REJECTED', trim($complete('forged:'.$recipientId)));
        $this->assertSame('COMPLETED', trim($complete($token.':'.$recipientId)));

        $this->assertSame(RecipientStatus::Sent, $recipient->fresh()->status);
        $this->assertSame('bc-msg', $recipient->fresh()->waha_message_id);
    }
}
