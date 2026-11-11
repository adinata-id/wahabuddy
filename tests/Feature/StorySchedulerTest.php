<?php

namespace Tests\Feature;

use App\Actions\Stories\ClaimStory;
use App\Actions\Stories\CompleteStory;
use App\Actions\Stories\ResolveStory;
use App\Enums\SendAttemptOutcome;
use App\Enums\SendAttemptSubject;
use App\Enums\StoryStatus;
use App\Jobs\SendStoryJob;
use App\Models\MediaFile;
use App\Models\SchedulerRun;
use App\Models\SendAttempt;
use App\Models\Story;
use App\Models\User;
use App\Models\WahaConnection;
use App\Services\Media\MediaUrlResolver;
use App\Services\Sending\SessionSendGate;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\DTO\ConnectionSnapshot;
use App\Services\Waha\DTO\SendResult;
use App\Services\Waha\WahaClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StorySchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Configured connection whose last test passed WORKING at revision 1.
     */
    private function workingConnection(int $revision = 1): WahaConnection
    {
        return WahaConnection::singleton()->forceFill([
            'base_url' => 'http://waha.test',
            'api_key' => 'key',
            'session' => 'default',
            'revision' => $revision,
            'last_test_status' => 'working',
            'last_test_at' => now(),
        ])->save() ? WahaConnection::singleton() : WahaConnection::singleton();
    }

    private function makeStory(array $overrides = []): Story
    {
        $story = new Story;
        $story->forceFill(array_merge([
            'type' => 'text',
            'text' => 'Hello',
            'scheduled_at' => now()->subMinute(),
            'status' => StoryStatus::Pending,
            'attempts' => 0,
            'connection_revision' => 1,
        ], $overrides));
        $story->save();

        return $story;
    }

    private function runJob(Story $story): void
    {
        (new SendStoryJob($story->id))->handle(
            app(ConnectionRepository::class),
            app(ClaimStory::class),
            app(CompleteStory::class),
            app(WahaClient::class),
            app(SessionSendGate::class),
            app(MediaUrlResolver::class),
        );
    }

    public function test_dispatcher_selects_due_marks_boundary_and_expires(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        Queue::fake();

        $due = $this->makeStory(['scheduled_at' => now()->subMinute()]);
        $future = $this->makeStory(['scheduled_at' => now()->addHour()]);

        // Exact 7-day boundary is still sendable; one second older is expired.
        $boundary = $this->makeStory(['scheduled_at' => now()->subDays(7)]);
        $tooOld = $this->makeStory(['scheduled_at' => now()->subDays(7)->subSecond()]);

        $this->artisan('waha:send-stories')->assertSuccessful();

        Queue::assertPushed(SendStoryJob::class, 2);
        Queue::assertPushed(SendStoryJob::class, fn (SendStoryJob $job) => $job->storyId === $due->id);
        Queue::assertPushed(SendStoryJob::class, fn (SendStoryJob $job) => $job->storyId === $boundary->id);
        Queue::assertNotPushed(SendStoryJob::class, fn (SendStoryJob $job) => $job->storyId === $future->id);

        $this->assertSame(StoryStatus::Expired, $tooOld->fresh()->status);
        $this->assertSame(StoryStatus::Pending, $future->fresh()->status);

        $run = SchedulerRun::latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(3, $run->processed_count);
    }

    public function test_dispatcher_is_quiet_when_nothing_due(): void
    {
        Queue::fake();
        $this->artisan('waha:send-stories')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->assertTrue(
            SchedulerRun::latest('id')->firstOrFail()->status->value === 'succeeded'
        );
    }

    public function test_successful_send_persists_ids(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => 'msg-1', 'key' => ['id' => 'key-1']], 200)]);

        $story = $this->makeStory();
        $this->runJob($story);

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Sent, $story->status);
        $this->assertSame('msg-1', $story->waha_message_id);
        $this->assertSame('key-1', $story->waha_key_id);
        $this->assertSame(1, $story->attempts);
        $this->assertNotNull($story->sent_at);

        $attempt = SendAttempt::firstOrFail();
        $this->assertSame(SendAttemptOutcome::Sent, $attempt->outcome);
        $this->assertSame('msg-1', $attempt->message_id);
        $this->assertNotNull($attempt->finished_at);
    }

    public function test_send_fails_with_explicit_cause_when_media_is_no_longer_sendable(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();

        // Legacy local-disk row: previews still work, but there is no URL a
        // remote WAHA could fetch, so the story must fail explicitly instead
        // of WAHA being handed a dead URL.
        $media = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/legacy.jpg',
            'original_name' => 'legacy.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'created_by' => User::factory()->create()->id,
        ]);

        $story = $this->makeStory(['type' => 'image', 'media_file_id' => $media->id]);
        $this->runJob($story);

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame('media_unavailable', $story->last_error_code);
        $this->assertStringContainsString('no longer available', (string) $story->last_error);
        $this->assertNull($story->next_attempt_at);
        $this->assertSame(0, $story->attempts, 'A missing URL must not burn a send attempt.');
        Http::assertNothingSent();

        $this->assertDatabaseHas('story_logs', [
            'story_id' => $story->id,
            'event' => 'failed_held',
        ]);
    }

    public function test_dispatcher_does_not_redispatch_stories_held_for_admin(): void
    {
        Queue::fake();

        // Held rows are Failed with no retry scheduled (deterministic
        // rejections, media no longer sendable): they wait for an explicit
        // admin retry and must leave the due set — otherwise every tick
        // re-queues a no-op job forever.
        $this->makeStory([
            'status' => StoryStatus::Failed,
            'next_attempt_at' => null,
            'last_error' => 'held for admin',
        ]);

        $this->artisan('waha:send-stories')->assertSuccessful();
        Queue::assertNotPushed(SendStoryJob::class);

        // A retryable failure whose backoff has elapsed is due again.
        $retryable = $this->makeStory([
            'status' => StoryStatus::Failed,
            'next_attempt_at' => now()->subMinute(),
        ]);

        $this->artisan('waha:send-stories')->assertSuccessful();
        Queue::assertPushed(SendStoryJob::class, fn (SendStoryJob $job) => $job->storyId === $retryable->id);
        Queue::assertPushed(SendStoryJob::class, 1);
    }

    public function test_media_unavailable_failure_writes_the_log_once(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();

        $media = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/legacy.jpg',
            'original_name' => 'legacy.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'created_by' => User::factory()->create()->id,
        ]);

        $story = $this->makeStory(['type' => 'image', 'media_file_id' => $media->id]);

        // A duplicate worker (e.g. queued before the first finished) must
        // not append a second failure log for the same cause.
        $this->runJob($story);
        $this->runJob($story->fresh());

        $this->assertSame(StoryStatus::Failed, $story->fresh()->status);
        $this->assertSame(1, $story->logs()->where('event', 'failed_held')->count());
    }

    public function test_credential_failure_holds_without_burning_attempts(): void
    {
        // Last connection test failed: sends are held, attempts not burned.
        $connection = WahaConnection::singleton();
        $connection->forceFill([
            'base_url' => 'http://waha.test',
            'api_key' => 'key',
            'last_test_status' => 'failing',
        ])->save();

        Http::preventStrayRequests();

        $story = $this->makeStory();
        $this->runJob($story);

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Pending, $story->status);
        $this->assertSame(0, $story->attempts);
        Http::assertNothingSent();

        $this->assertDatabaseHas('story_logs', [
            'story_id' => $story->id,
            'event' => 'held',
        ]);
    }

    public function test_revision_mismatch_holds_old_work(): void
    {
        // Current target moved to revision 2; the story targets revision 1.
        $this->workingConnection(revision: 2);
        Http::preventStrayRequests();

        $story = $this->makeStory();
        $this->runJob($story);

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Pending, $story->status);
        $this->assertSame(0, $story->attempts);
        Http::assertNothingSent();
        $this->assertDatabaseHas('story_logs', [
            'story_id' => $story->id,
            'event' => 'held',
        ]);
    }

    public function test_attempt_cap_and_safe_backoff(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();

        // Rate limited without Retry-After: safe ladder 60s then 300s.
        Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

        $story = $this->makeStory();
        $this->runJob($story);
        $story = $story->fresh();
        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame(1, $story->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $story->next_attempt_at->timestamp, 1);

        $story->forceFill(['next_attempt_at' => now()->subMinute()])->save();
        $this->runJob($story);
        $story = $story->fresh();
        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame(2, $story->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(300)->timestamp, $story->next_attempt_at->timestamp, 1);

        // Third failure hits the cap: failed_max, no fourth attempt.
        $story->forceFill(['next_attempt_at' => now()->subMinute()])->save();
        $this->runJob($story);
        $story = $story->fresh();
        $this->assertSame(StoryStatus::FailedMax, $story->status);
        $this->assertSame(3, $story->attempts);
        $this->assertNull($story->next_attempt_at);

        // The claim refuses a fourth attempt even when forced due.
        $this->runJob($story);
        $this->assertSame(3, $story->fresh()->attempts);
        Http::assertSentCount(3);
    }

    public function test_deterministic_rejection_is_held_without_auto_retry(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'invalid font'], 422)]);

        $story = $this->makeStory();
        $this->runJob($story);

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame(1, $story->attempts);
        $this->assertNull($story->next_attempt_at, 'Deterministic rejections must not auto-loop.');
        $this->assertSame('request_rejected', $story->last_error_code);
    }

    public function test_timeout_becomes_unknown_without_auto_retry(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();

        // If anything retried, the second stub would succeed with an id and
        // the story would become sent — the asserts below would fail.
        Http::fakeSequence('*')
            ->pushFailedConnection('cURL error 28: timed out')
            ->push(['id' => 'would-prove-retry'], 200);

        $story = $this->makeStory();
        $this->runJob($story);

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Unknown, $story->status);
        $this->assertSame('network_timeout', $story->last_error_code);
        $this->assertNull($story->next_attempt_at);
        $this->assertSame(1, $story->attempts);

        $attempt = SendAttempt::firstOrFail();
        $this->assertSame(SendAttemptOutcome::Unknown, $attempt->outcome);
    }

    public function test_stale_sending_becomes_unknown_not_pending(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');

        $story = $this->makeStory([
            'status' => StoryStatus::Sending,
            'attempts' => 1,
            'attempt_token' => 'stale-token',
            'sending_started_at' => now()->subMinutes(20),
        ]);

        SendAttempt::create([
            'attempt_token' => 'stale-token',
            'subject_type' => SendAttemptSubject::Story,
            'subject_id' => $story->id,
            'connection_revision' => 1,
            'started_at' => now()->subMinutes(20),
            'outcome' => SendAttemptOutcome::Started,
        ]);

        $this->artisan('waha:recover-stale-sends', ['--minutes' => 15])->assertSuccessful();

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Unknown, $story->status);
        $this->assertNull($story->attempt_token);
        $this->assertSame('stale_sending_recovered', $story->last_error_code);

        $attempt = SendAttempt::where('attempt_token', 'stale-token')->firstOrFail();
        $this->assertSame(SendAttemptOutcome::Unknown, $attempt->outcome);
        $this->assertNotNull($attempt->finished_at);
    }

    public function test_worker_crash_after_acceptance_heals_from_proof(): void
    {
        $story = $this->makeStory(['status' => StoryStatus::Sending]);

        // The worker died after WAHA accepted the send but before completing
        // the story row; the attempt evidence survived.
        SendAttempt::create([
            'attempt_token' => 'crashed-token',
            'subject_type' => SendAttemptSubject::Story,
            'subject_id' => $story->id,
            'connection_revision' => 1,
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(29),
            'outcome' => SendAttemptOutcome::Sent,
            'message_id' => 'proof-msg',
            'key_id' => 'proof-key',
        ]);

        Http::preventStrayRequests();

        // The dispatcher's claim path heals from proof without new HTTP.
        $claim = app(ClaimStory::class);
        $this->assertNull($claim->handle($story->id, app(ConnectionRepository::class)->snapshot()));

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Sent, $story->status);
        $this->assertSame('proof-msg', $story->waha_message_id);
        Http::assertNothingSent();
    }

    public function test_stale_worker_token_cannot_overwrite_newer_attempt(): void
    {
        $story = $this->makeStory([
            'status' => StoryStatus::Sending,
            'attempt_token' => 'new-token',
            'attempts' => 2,
            'sending_started_at' => now(),
        ]);

        // A stale worker from attempt one tries to complete with the old token.
        $complete = app(CompleteStory::class);
        $result = new SendResult(messageId: 'stale-msg', keyId: null);

        $this->assertFalse($complete->handle($story->id, 'old-token', $result));

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Sending, $story->status);
        $this->assertSame('new-token', $story->attempt_token);
        $this->assertNull($story->waha_message_id);
    }

    public function test_resolve_unknown_requires_note_and_audits_decisions(): void
    {
        $resolve = app(ResolveStory::class);

        $story = $this->makeStory(['status' => StoryStatus::Unknown]);

        $this->expectException(\InvalidArgumentException::class);
        $resolve->handle($story, 'mark_sent', '  ');
    }

    public function test_resolve_mark_sent_uses_proof_or_admin_confirmation(): void
    {
        $resolve = app(ResolveStory::class);

        // With stored proof: ids restored, no fabrication.
        $story = $this->makeStory(['status' => StoryStatus::Unknown]);

        SendAttempt::create([
            'attempt_token' => 'proof-token',
            'subject_type' => SendAttemptSubject::Story,
            'subject_id' => $story->id,
            'connection_revision' => 1,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'outcome' => SendAttemptOutcome::Sent,
            'message_id' => 'real-id',
            'key_id' => 'real-key',
        ]);

        $resolve->handle($story, 'mark_sent', 'Verified in WhatsApp by admin.');

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Sent, $story->status);
        $this->assertSame('real-id', $story->waha_message_id);

        // Without proof: admin confirmation recorded, no invented WAHA id.
        $other = $this->makeStory(['status' => StoryStatus::Unknown]);

        $resolve->handle($other, 'mark_sent', 'Checked the phone; status was posted.');

        $other = $other->fresh();
        $this->assertSame(StoryStatus::Sent, $other->status);
        $this->assertNull($other->waha_message_id);

        $this->assertDatabaseHas('story_logs', [
            'story_id' => $other->id,
            'event' => 'resolved_mark_sent',
        ]);
    }

    public function test_resolve_retry_acknowledges_duplication_risk(): void
    {
        $resolve = app(ResolveStory::class);
        $story = $this->makeStory(['status' => StoryStatus::Unknown, 'attempts' => 2]);

        $resolve->handle($story, 'retry', 'Admin accepts possible duplicate.');

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Pending, $story->status);
        $this->assertSame(0, $story->attempts);
        $this->assertNull($story->next_attempt_at);

        $this->assertDatabaseHas('story_logs', [
            'story_id' => $story->id,
            'event' => 'resolved_retry',
        ]);
    }

    public function test_session_gate_serializes_and_pacing_waits(): void
    {
        $snapshot = new ConnectionSnapshot('http://waha.test', null, null, null, 'default', 1, []);
        $gate = app(SessionSendGate::class);

        $first = $gate->acquire($snapshot);
        $this->assertNotNull($first);

        // Same session: second acquisition fails while the lease is held.
        $this->assertNull($gate->acquire($snapshot));

        // Release is safe and re-acquisition works.
        $first->release();
        $second = $gate->acquire($snapshot);
        $this->assertNotNull($second);
        $second->release();

        // Pacing timestamp: next allowed send moves into the future.
        $this->assertNull($gate->nextAllowedAt($snapshot));
        $gate->recordPacing($snapshot, 20);
        $this->assertTrue($gate->nextAllowedAt($snapshot)->isFuture());
    }
}
