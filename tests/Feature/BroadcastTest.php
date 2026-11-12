<?php

namespace Tests\Feature;

use App\Actions\Broadcasts\CancelBroadcast;
use App\Actions\Broadcasts\ClaimRecipient;
use App\Actions\Broadcasts\CompleteRecipient;
use App\Actions\Broadcasts\PauseBroadcast;
use App\Actions\Broadcasts\QueueBroadcast;
use App\Actions\Broadcasts\ResolveRecipient;
use App\Actions\Broadcasts\ResumeBroadcast;
use App\Enums\BroadcastStatus;
use App\Enums\BroadcastType;
use App\Enums\RecipientStatus;
use App\Enums\StoryStatus;
use App\Jobs\ProcessBroadcastJob;
use App\Models\Broadcast;
use App\Models\MediaFile;
use App\Models\S3Connection;
use App\Models\Story;
use App\Models\User;
use App\Models\WahaConnection;
use App\Services\Broadcasts\PacingClock;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUrlResolver;
use App\Services\Sending\SessionSendGate;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function workingConnection(int $revision = 1): void
    {
        WahaConnection::singleton()->forceFill([
            'base_url' => 'http://waha.test',
            'api_key' => 'key',
            'session' => 'default',
            'revision' => $revision,
            'last_test_status' => 'working',
        ])->save();
    }

    private function makeBroadcast(array $overrides = []): Broadcast
    {
        $broadcast = new Broadcast;
        $broadcast->forceFill(array_merge([
            'name' => 'Promo',
            'type' => BroadcastType::Text,
            'template_text' => 'Hi {name}!',
            'status' => BroadcastStatus::Draft,
            'connection_revision' => 1,
            'batch_count' => 0,
        ], $overrides));
        $broadcast->save();

        return $broadcast;
    }

    private function queueWith(Broadcast $broadcast, array $recipients): void
    {
        Queue::fake();
        app(QueueBroadcast::class)->handle($broadcast, $recipients);
        Queue::assertPushed(ProcessBroadcastJob::class);
    }

    /**
     * Runs one ProcessBroadcastJob synchronously with real dependencies.
     */
    private function runJob(Broadcast $broadcast): void
    {
        (new ProcessBroadcastJob($broadcast->id))->handle(
            app(ConnectionRepository::class),
            app(ClaimRecipient::class),
            app(CompleteRecipient::class),
            app(WahaClient::class),
            app(SessionSendGate::class),
            app(MediaUrlResolver::class),
            app(MediaStorage::class),
        );
    }

    public function test_queue_snapshots_recipients_and_dedupes_repeated_requests(): void
    {
        $this->workingConnection();
        $broadcast = $this->makeBroadcast();

        $this->queueWith($broadcast, [
            ['chat_id' => '628111111111@c.us', 'name' => 'Andi'],
            ['chat_id' => '628111111111@c.us', 'name' => 'Andi duplicate'],
            ['chat_id' => '628222222222@c.us', 'name' => null],
        ]);

        $this->assertSame(BroadcastStatus::Queued, $broadcast->fresh()->status);

        $recipients = $broadcast->recipients()->orderBy('chat_id')->get();
        $this->assertCount(2, $recipients);
        $this->assertSame('Hi Andi!', $recipients[0]->rendered_text);
        $this->assertSame('Hi there!', $recipients[1]->rendered_text, 'Empty names fall back to "there".');

        // Repeated queue request can never add recipients again.
        app(QueueBroadcast::class)->handle($broadcast->fresh(), [
            ['chat_id' => '628111111111@c.us', 'name' => 'Andi'],
            ['chat_id' => '628333333333@c.us', 'name' => 'Citra'],
        ]);

        $this->assertCount(3, $broadcast->recipients()->get(), 'New distinct recipients are added; duplicates ignored.');
        $this->assertSame(1, $broadcast->recipients()->where('chat_id', '628111111111@c.us')->count());
    }

    public function test_pacing_waits_and_random_delay_stays_in_range(): void
    {
        $this->workingConnection();
        Queue::fake();
        Http::fake(['*' => Http::response(['id' => 'm1'], 200)]);

        $clock = app(PacingClock::class);
        for ($i = 0; $i < 40; $i++) {
            $delay = $clock->randomDelaySeconds();
            $this->assertGreaterThanOrEqual(8, $delay);
            $this->assertLessThanOrEqual(20, $delay);
        }

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running, 'next_send_at' => now()->subSecond()]);
        $broadcast->recipients()->createMany([
            ['chat_id' => '628111111111@c.us', 'rendered_text' => 'Hi!', 'status' => RecipientStatus::Pending],
        ]);

        $this->runJob($broadcast);

        $recipient = $broadcast->recipients()->first();
        $this->assertSame(RecipientStatus::Sent, $recipient->status);
        $this->assertSame('m1', $recipient->waha_message_id);

        // next_send_at moved to completion + delay within [8, 20] seconds.
        $next = $broadcast->fresh()->next_send_at;
        $this->assertNotNull($next);
        $this->assertGreaterThanOrEqual(now()->addSeconds(8)->timestamp - 2, $next->timestamp);
        $this->assertLessThanOrEqual(now()->addSeconds(20)->timestamp + 2, $next->timestamp);
    }

    public function test_batch_pause_after_50_attempts_and_two_campaigns_share_pacing(): void
    {
        $this->workingConnection();
        Queue::fake();
        Http::fake(['*' => Http::response(['id' => 'm'], 200)]);

        $first = $this->makeBroadcast(['name' => 'A']);
        $first->recipients()->createMany(
            collect(range(1, 55))->map(fn () => [
                'chat_id' => '62'.uniqid().'@c.us',
                'rendered_text' => 'x',
                'status' => RecipientStatus::Sent,
            ])->all()
        );
        $first->forceFill(['status' => BroadcastStatus::Running, 'batch_count' => 49, 'next_send_at' => now()->subSecond()])->save();
        // One more pending recipient: the 50th attempt triggers the pause.
        $first->recipients()->create([
            'chat_id' => '628999999999@c.us',
            'rendered_text' => 'x',
            'status' => RecipientStatus::Pending,
        ]);

        $this->runJob($first);

        $fresh = $first->fresh();
        $this->assertSame(0, $fresh->batch_count, 'Batch counter resets after the pause.');
        $this->assertTrue($fresh->next_send_at->isFuture());
        $this->assertGreaterThanOrEqual(now()->addSeconds(60)->getTimestamp() - 2, $fresh->next_send_at->getTimestamp());

        $this->assertDatabaseHas('broadcast_logs', [
            'broadcast_id' => $first->id,
            'event' => 'batch_pause',
        ]);

        // The shared session pacing stamp also moved forward for other campaigns.
        $gate = app(SessionSendGate::class);
        $next = $gate->nextAllowedAt(app(ConnectionRepository::class)->snapshot());
        $this->assertNotNull($next, 'Session-wide pacing applies across campaigns on the same session.');
        $this->assertTrue($next->isFuture());
    }

    public function test_revision_mismatch_holds_campaign(): void
    {
        $this->workingConnection(revision: 2);
        Http::preventStrayRequests();

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running, 'connection_revision' => 1, 'next_send_at' => now()]);
        $broadcast->recipients()->create([
            'chat_id' => '628111111111@c.us',
            'rendered_text' => 'x',
            'status' => RecipientStatus::Pending,
        ]);

        $this->runJob($broadcast);

        $recipient = $broadcast->recipients()->first();
        $this->assertSame(RecipientStatus::Pending, $recipient->status);
        $this->assertSame(0, $recipient->attempts);
        Http::assertNothingSent();

        $this->assertDatabaseHas('broadcast_logs', [
            'broadcast_id' => $broadcast->id,
            'event' => 'held',
        ]);
    }

    public function test_pause_after_enqueue_sends_nothing_for_unstarted_recipients(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();

        $broadcast = $this->makeBroadcast();
        $this->queueWith($broadcast, [
            ['chat_id' => '628111111111@c.us', 'name' => 'Andi'],
            ['chat_id' => '628222222222@c.us', 'name' => 'Budi'],
        ]);

        app(PauseBroadcast::class)->handle($broadcast->fresh());
        $this->assertSame(BroadcastStatus::Paused, $broadcast->fresh()->status);

        // Jobs waking while paused are no-ops: zero POSTs, zero attempts.
        $this->runJob($broadcast->fresh());
        $this->runJob($broadcast->fresh());

        Http::assertNothingSent();
        $this->assertSame(0, $broadcast->recipients()->sum('attempts'));
        $this->assertSame(RecipientStatus::Pending, $broadcast->recipients()->first()->status);

        // Resume only works from paused and restarts the pipeline.
        app(ResumeBroadcast::class)->handle($broadcast->fresh());
        $this->assertSame(BroadcastStatus::Running, $broadcast->fresh()->status);

        // Resuming a running campaign is rejected.
        $this->expectException(\InvalidArgumentException::class);
        app(ResumeBroadcast::class)->handle($broadcast->fresh());
    }

    public function test_cancel_is_terminal_and_marks_unstarted_recipients(): void
    {
        $this->workingConnection();

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running]);
        $r1 = $broadcast->recipients()->create(['chat_id' => '628111111111@c.us', 'rendered_text' => 'x', 'status' => RecipientStatus::Pending]);
        $broadcast->recipients()->create(['chat_id' => '628222222222@c.us', 'rendered_text' => 'x', 'status' => RecipientStatus::Sent, 'sent_at' => now()]);
        $broadcast->recipients()->create(['chat_id' => '628333333333@c.us', 'rendered_text' => 'x', 'status' => RecipientStatus::Sending, 'attempt_token' => 'in-flight']);

        app(CancelBroadcast::class)->handle($broadcast->fresh());

        $fresh = $broadcast->fresh();
        $this->assertSame(BroadcastStatus::Canceled, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertSame(RecipientStatus::Canceled, $r1->fresh()->status);

        // In-flight send is untouched: it may still complete as sent.
        $this->assertSame(
            RecipientStatus::Sending,
            $broadcast->recipients()->where('attempt_token', 'in-flight')->first()->status
        );

        $this->expectException(\InvalidArgumentException::class);
        app(CancelBroadcast::class)->handle($fresh);
    }

    public function test_image_broadcast_recipient_fails_when_media_is_no_longer_sendable(): void
    {
        $this->workingConnection();
        Queue::fake();
        Http::preventStrayRequests();

        // Legacy local-disk row: there is no fresh presigned URL a remote
        // WAHA could fetch, so the recipient must fail with an explicit
        // cause instead of the worker crashing on the client guard.
        $media = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/legacy.jpg',
            'original_name' => 'legacy.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'created_by' => User::factory()->create()->id,
        ]);

        $broadcast = $this->makeBroadcast([
            'type' => BroadcastType::Image,
            'media_file_id' => $media->id,
            'status' => BroadcastStatus::Running,
            'next_send_at' => now()->subSecond(),
        ]);
        $broadcast->recipients()->createMany([
            ['chat_id' => '628111111111@c.us', 'rendered_text' => 'Hi!', 'status' => RecipientStatus::Pending],
            ['chat_id' => '628222222222@c.us', 'rendered_text' => 'Hi!', 'status' => RecipientStatus::Pending],
        ]);

        $this->runJob($broadcast);

        $failed = $broadcast->recipients()->where('chat_id', '628111111111@c.us')->first();
        $this->assertSame(RecipientStatus::Failed, $failed->status);
        $this->assertSame('media_unavailable', $failed->last_error_code);
        $this->assertStringContainsString('no longer available', (string) $failed->last_error);
        $this->assertNull($failed->next_attempt_at, 'A missing URL is deterministic: held for the admin, never auto-retried.');
        Http::assertNothingSent();

        // The cause is campaign-wide and deterministic: pause instead of
        // pacing through every remaining recipient as a failure.
        $this->assertSame(BroadcastStatus::Paused, $broadcast->fresh()->status);
        $untouched = $broadcast->recipients()->where('chat_id', '628222222222@c.us')->first();
        $this->assertSame(RecipientStatus::Pending, $untouched->status);

        $this->assertDatabaseHas('broadcast_logs', [
            'broadcast_id' => $broadcast->id,
            'event' => 'failed_held',
        ]);
        $this->assertDatabaseHas('broadcast_logs', [
            'broadcast_id' => $broadcast->id,
            'event' => 'paused_media_unavailable',
        ]);
    }

    public function test_image_recipient_fails_with_disabled_storage_variant_message(): void
    {
        $this->workingConnection();
        Queue::fake();
        Http::preventStrayRequests();
        $this->swapMediaStorageWithFake();
        S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();

        $media = MediaFile::create([
            'disk' => 's3', 'path' => 'stories/2026/11/x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'created_by' => User::factory()->create()->id,
        ]);

        $broadcast = $this->makeBroadcast([
            'type' => BroadcastType::Image,
            'media_file_id' => $media->id,
            'status' => BroadcastStatus::Running,
            'next_send_at' => now()->subSecond(),
        ]);
        $broadcast->recipients()->createMany([
            ['chat_id' => '628111111111@c.us', 'rendered_text' => 'Hi!', 'status' => RecipientStatus::Pending],
            ['chat_id' => '628222222222@c.us', 'rendered_text' => 'Hi!', 'status' => RecipientStatus::Pending],
        ]);

        $this->runJob($broadcast);

        $failed = $broadcast->recipients()->where('chat_id', '628111111111@c.us')->first();
        $this->assertSame(RecipientStatus::Failed, $failed->status);
        $this->assertSame('media_unavailable', $failed->last_error_code);
        $this->assertStringContainsString('disabled or not configured', (string) $failed->last_error);
        Http::assertNothingSent();

        // The pause message must name the actual cause: disabled storage the
        // admin can re-enable, not a purged file needing a re-upload.
        $this->assertSame(BroadcastStatus::Paused, $broadcast->fresh()->status);

        $pauseLog = $broadcast->logs()->where('event', 'paused_media_unavailable')->firstOrFail();
        $this->assertStringContainsString('disabled or not configured', (string) $pauseLog->message);
    }

    public function test_broadcasts_proceed_while_a_story_is_held_for_admin(): void
    {
        $this->workingConnection();
        Queue::fake();
        Http::fake(['*' => Http::response(['id' => 'm1'], 200)]);

        // A held story (Failed, no retry scheduled) is not "due" work: it
        // must not make every broadcast job yield forever.
        Story::create([
            'type' => 'text',
            'text' => 'held',
            'scheduled_at' => now()->subMinute(),
            'status' => StoryStatus::Failed,
            'next_attempt_at' => null,
            'connection_revision' => 1,
        ]);

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running, 'next_send_at' => now()->subSecond()]);
        $broadcast->recipients()->create([
            'chat_id' => '628111111111@c.us',
            'rendered_text' => 'Hi!',
            'status' => RecipientStatus::Pending,
        ]);

        $this->runJob($broadcast);

        $this->assertSame(RecipientStatus::Sent, $broadcast->recipients()->first()->status);
    }

    public function test_unknown_outcome_pauses_campaign_and_needs_explicit_resolution(): void
    {
        $this->workingConnection();
        Queue::fake();
        Http::fake(['*' => Http::response(['boom'], 500)]);

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running, 'next_send_at' => now()->subSecond()]);
        $recipient = $broadcast->recipients()->create([
            'chat_id' => '628111111111@c.us',
            'rendered_text' => 'x',
            'status' => RecipientStatus::Pending,
        ]);

        $this->runJob($broadcast);

        $recipient = $recipient->fresh();
        $this->assertSame(RecipientStatus::Unknown, $recipient->status);
        $this->assertSame(BroadcastStatus::Paused, $broadcast->fresh()->status);
        $this->assertDatabaseHas('broadcast_logs', ['broadcast_id' => $broadcast->id, 'event' => 'paused_unknown']);

        // Resolution: mark_sent without note is refused; with note it is
        // audited and the campaign STAYS paused (no auto-resume).
        $resolver = app(ResolveRecipient::class);

        try {
            $resolver->handle($recipient->fresh(), 'mark_sent', '  ');
            $this->fail('Expected note requirement.');
        } catch (\InvalidArgumentException) {
        }

        $resolver->handle($recipient->fresh(), 'mark_sent', 'Verified on the phone.');

        $this->assertSame(RecipientStatus::Sent, $recipient->fresh()->status);
        $this->assertSame(BroadcastStatus::Paused, $broadcast->fresh()->status, 'Resolution must not auto-resume the campaign.');

        app(ResumeBroadcast::class)->handle($broadcast->fresh());
        $this->assertSame(BroadcastStatus::Running, $broadcast->fresh()->status);
    }

    public function test_attempt_cap_and_backoff_for_recipients(): void
    {
        $this->workingConnection();
        Queue::fake();
        Http::fake(['*' => Http::response(['slow down'], 429)]);

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running, 'next_send_at' => now()->subSecond()]);
        $recipient = $broadcast->recipients()->create([
            'chat_id' => '628111111111@c.us',
            'rendered_text' => 'x',
            'status' => RecipientStatus::Pending,
        ]);

        $this->runJob($broadcast);
        $recipient = $recipient->fresh();
        $this->assertSame(RecipientStatus::Failed, $recipient->status);
        $this->assertSame(1, $recipient->attempts);

        // Backoff: 429 without Retry-After falls back to 60 seconds.
        $this->assertNotNull($recipient->next_attempt_at);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $recipient->next_attempt_at->timestamp, 2);

        // Second failure escalates to 300s; third hits the cap.
        cache()->flush();
        $recipient->forceFill(['status' => RecipientStatus::Pending, 'next_attempt_at' => now()->subMinute()])->save();
        $broadcast = $broadcast->fresh();
        $broadcast->forceFill(['next_send_at' => now()->subSecond()])->save();
        $this->runJob($broadcast);
        $this->assertSame(2, $recipient->fresh()->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(300)->timestamp, $recipient->fresh()->next_attempt_at->timestamp, 2);

        cache()->flush();
        $recipient = $recipient->fresh();
        $recipient->forceFill(['status' => RecipientStatus::Pending, 'next_attempt_at' => now()->subMinute()])->save();
        $broadcast = $broadcast->fresh();
        $broadcast->forceFill(['next_send_at' => now()->subSecond()])->save();
        $this->runJob($broadcast);

        $this->assertSame(RecipientStatus::FailedMax, $recipient->fresh()->status);
        $this->assertSame(3, $recipient->fresh()->attempts);
    }

    public function test_sweeper_recovers_campaign_after_crash_before_dispatch(): void
    {
        Queue::fake();

        $this->workingConnection();

        // Campaign runnable, recipient pending, but no follow-up job was ever
        // dispatched (worker crashed after persisting the previous outcome).
        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running, 'next_send_at' => now()->subMinute()]);
        $broadcast->recipients()->create([
            'chat_id' => '628111111111@c.us',
            'rendered_text' => 'x',
            'status' => RecipientStatus::Pending,
        ]);

        $this->artisan('waha:recover-broadcasts')->assertSuccessful();

        Queue::assertPushed(ProcessBroadcastJob::class, fn (ProcessBroadcastJob $job) => $job->broadcastId === $broadcast->id);
    }

    public function test_completed_counts_derive_from_recipient_aggregation(): void
    {
        $this->workingConnection();
        Queue::fake();

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running]);
        $broadcast->recipients()->createMany([
            ['chat_id' => '1@c.us', 'rendered_text' => 'x', 'status' => RecipientStatus::Sent, 'sent_at' => now()],
            ['chat_id' => '2@c.us', 'rendered_text' => 'x', 'status' => RecipientStatus::Failed],
            ['chat_id' => '3@c.us', 'rendered_text' => 'x', 'status' => RecipientStatus::Canceled],
        ]);

        // A job waking with nothing claimable advances the campaign to
        // completed; counts come from recipient aggregation, not a counter.
        $this->runJob($broadcast);

        $fresh = $broadcast->fresh();
        $this->assertSame(BroadcastStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertDatabaseHas('broadcast_logs', ['broadcast_id' => $broadcast->id, 'event' => 'completed']);
    }
}
