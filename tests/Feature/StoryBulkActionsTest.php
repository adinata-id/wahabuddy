<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\User;
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
}
