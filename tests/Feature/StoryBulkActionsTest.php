<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\User;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function makeStory(StoryStatus $status): Story
    {
        $story = new Story;
        $story->forceFill([
            'type' => 'text',
            'text' => 'x',
            'scheduled_at' => now()->addDay(),
            'status' => $status,
            'attempts' => $status === StoryStatus::Failed ? 2 : 0,
            'connection_revision' => 1,
        ]);
        $story->save();

        return $story;
    }

    public function test_guests_cannot_bulk_update(): void
    {
        $this->post(route('stories.bulk'), ['action' => 'cancel', 'ids' => [1]])
            ->assertRedirect('/login');

        $this->postJson(route('stories.bulk'), ['action' => 'cancel', 'ids' => [1]])
            ->assertUnauthorized();
    }

    public function test_bulk_cancel_applies_only_to_eligible_stories(): void
    {
        $this->actingAs($this->admin());

        $pending = $this->makeStory(StoryStatus::Pending);
        $failed = $this->makeStory(StoryStatus::Failed);
        $sent = $this->makeStory(StoryStatus::Sent);
        $sending = $this->makeStory(StoryStatus::Sending);

        $response = $this->postJson(route('stories.bulk'), [
            'action' => 'cancel',
            'ids' => [$pending->id, $failed->id, $sent->id, $sending->id],
        ]);

        $response->assertOk()->assertJson(['updated' => 2, 'skipped' => 2]);

        $this->assertSame(StoryStatus::Canceled, $pending->fresh()->status);
        $this->assertSame(StoryStatus::Canceled, $failed->fresh()->status);
        $this->assertNull($pending->fresh()->next_attempt_at);

        // Ineligible rows are untouched.
        $this->assertSame(StoryStatus::Sent, $sent->fresh()->status);
        $this->assertSame(StoryStatus::Sending, $sending->fresh()->status);

        // Every applied change is audited.
        $this->assertDatabaseHas('story_logs', ['story_id' => $pending->id, 'event' => 'canceled']);
        $this->assertDatabaseHas('story_logs', ['story_id' => $failed->id, 'event' => 'canceled']);
    }

    public function test_bulk_retry_opens_new_cycles(): void
    {
        $this->actingAs($this->admin());

        $failedMax = $this->makeStory(StoryStatus::FailedMax);
        $canceled = $this->makeStory(StoryStatus::Canceled);
        $unknown = $this->makeStory(StoryStatus::Unknown);

        $response = $this->postJson(route('stories.bulk'), [
            'action' => 'retry',
            'ids' => [$failedMax->id, $canceled->id, $unknown->id],
        ]);

        $response->assertOk()->assertJson(['updated' => 2, 'skipped' => 1]);

        $this->assertSame(StoryStatus::Pending, $failedMax->fresh()->status);
        $this->assertSame(0, $failedMax->fresh()->attempts);
        $this->assertSame(StoryStatus::Pending, $canceled->fresh()->status);
        $this->assertSame(StoryStatus::Unknown, $unknown->fresh()->status, 'Unknown must be resolved explicitly, never bulk-retried.');
    }

    public function test_bulk_delete_skips_sending_and_unknown(): void
    {
        $this->actingAs($this->admin());

        $pending = $this->makeStory(StoryStatus::Pending);
        $unknown = $this->makeStory(StoryStatus::Unknown);
        $sending = $this->makeStory(StoryStatus::Sending);

        $response = $this->postJson(route('stories.bulk'), [
            'action' => 'delete',
            'ids' => [$pending->id, $unknown->id, $sending->id],
        ]);

        $response->assertOk()->assertJson(['updated' => 1, 'skipped' => 2]);

        $this->assertDatabaseMissing('stories', ['id' => $pending->id]);
        $this->assertDatabaseHas('stories', ['id' => $unknown->id]);
        $this->assertDatabaseHas('stories', ['id' => $sending->id]);
    }

    public function test_bulk_validates_action_and_ids(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('stories.bulk'), ['action' => 'explode', 'ids' => [1]])
            ->assertStatus(422)->assertInvalid(['action']);

        $this->postJson(route('stories.bulk'), ['action' => 'cancel', 'ids' => []])
            ->assertStatus(422)->assertInvalid(['ids']);
    }

    public function test_bulk_set_status_updates_eligible_stories(): void
    {
        $this->actingAs($this->admin());

        $sent = $this->makeStory(StoryStatus::Sent);
        $pending = $this->makeStory(StoryStatus::Pending);
        $alreadyCanceled = $this->makeStory(StoryStatus::Canceled);
        $sending = $this->makeStory(StoryStatus::Sending);
        $unknown = $this->makeStory(StoryStatus::Unknown);

        $response = $this->postJson(route('stories.bulk'), [
            'action' => 'set_status',
            'status' => 'canceled',
            'ids' => [$sent->id, $pending->id, $alreadyCanceled->id, $sending->id, $unknown->id],
        ]);

        $response->assertOk()->assertJson(['updated' => 2, 'skipped' => 3]);

        $this->assertSame(StoryStatus::Canceled, $sent->fresh()->status);
        $this->assertSame(StoryStatus::Canceled, $pending->fresh()->status);

        // Already-target, in-flight, and unknown rows are untouched.
        $this->assertSame(StoryStatus::Canceled, $alreadyCanceled->fresh()->status);
        $this->assertSame(StoryStatus::Sending, $sending->fresh()->status);
        $this->assertSame(StoryStatus::Unknown, $unknown->fresh()->status);

        // Every override is audited with the transition.
        $log = $sent->logs()->where('event', 'status_overridden')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->context['from']);
        $this->assertSame('canceled', $log->context['to']);
        $this->assertDatabaseHas('story_logs', ['story_id' => $pending->id, 'event' => 'status_overridden']);
    }

    public function test_bulk_set_status_pending_resets_attempt_cycle(): void
    {
        $this->actingAs($this->admin());

        $failed = $this->makeStory(StoryStatus::Failed);
        $failed->forceFill([
            'last_error' => 'connection refused',
            'last_error_code' => 'ECONNREFUSED',
            'next_attempt_at' => now()->addMinutes(5),
        ])->save();

        $this->postJson(route('stories.bulk'), [
            'action' => 'set_status',
            'status' => 'pending',
            'ids' => [$failed->id],
        ])->assertOk()->assertJson(['updated' => 1, 'skipped' => 0]);

        $fresh = $failed->fresh();
        $this->assertSame(StoryStatus::Pending, $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->last_error_code);
        $this->assertSame(
            app(ConnectionRepository::class)->current()->revision,
            $fresh->connection_revision,
        );
    }

    public function test_bulk_set_status_sent_sets_sent_at_without_fabricating_ids(): void
    {
        $this->actingAs($this->admin());

        $pending = $this->makeStory(StoryStatus::Pending);

        $this->postJson(route('stories.bulk'), [
            'action' => 'set_status',
            'status' => 'sent',
            'ids' => [$pending->id],
        ])->assertOk()->assertJson(['updated' => 1, 'skipped' => 0]);

        $fresh = $pending->fresh();
        $this->assertSame(StoryStatus::Sent, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNull($fresh->waha_message_id, 'A WAHA id is never fabricated.');
        $this->assertNull($fresh->waha_key_id, 'A WAHA key id is never fabricated.');
    }

    public function test_bulk_set_status_validates_target_status(): void
    {
        $this->actingAs($this->admin());

        // Manual override may never target the job-owned or resolve-only states.
        $this->postJson(route('stories.bulk'), ['action' => 'set_status', 'status' => 'sending', 'ids' => [1]])
            ->assertStatus(422)->assertInvalid(['status']);

        $this->postJson(route('stories.bulk'), ['action' => 'set_status', 'status' => 'unknown', 'ids' => [1]])
            ->assertStatus(422)->assertInvalid(['status']);

        // The target status is required for the set_status action.
        $this->postJson(route('stories.bulk'), ['action' => 'set_status', 'ids' => [1]])
            ->assertStatus(422)->assertInvalid(['status']);
    }
}
