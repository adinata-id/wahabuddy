<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\MediaFile;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Once cleanup purges a media file's storage, that file must never be
 * re-attached or re-sent: sends would fetch a missing object and fail at
 * WAHA with a confusing error.
 */
class PurgedMediaGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function purgedMedia(): MediaFile
    {
        Storage::fake('public');

        return MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/purged.jpg',
            'original_name' => 'purged.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'created_by' => $this->admin()->id,
            'purged_at' => now(),
        ]);
    }

    public function test_story_store_rejects_purged_media_with_422(): void
    {
        $this->actingAs($this->admin());
        $media = $this->purgedMedia();

        $this->post(route('stories.store'), [
            'type' => 'image',
            'media_file_id' => $media->id,
            'text' => 'caption',
            'scheduled_at' => '2026-11-01T09:00:00+07:00',
        ])->assertInvalid(['media_file_id']);

        $this->assertDatabaseCount('stories', 0);
    }

    public function test_broadcast_store_rejects_purged_media_with_422(): void
    {
        $this->actingAs($this->admin());
        $media = $this->purgedMedia();

        $this->post(route('broadcasts.store'), [
            'name' => 'Campaign',
            'type' => 'image',
            'template_text' => 'Hello!',
            'media_file_id' => $media->id,
        ])->assertInvalid(['media_file_id']);

        $this->assertDatabaseCount('broadcasts', 0);
    }

    public function test_story_retry_blocked_when_media_is_purged(): void
    {
        $this->actingAs($this->admin());
        $media = $this->purgedMedia();

        $story = Story::create([
            'type' => 'image',
            'media_file_id' => $media->id,
            'scheduled_at' => now()->subDays(10),
            'status' => StoryStatus::FailedMax,
            'connection_revision' => 1,
        ]);

        $this->post(route('stories.retry', $story))
            ->assertSessionHasErrors('story');

        $this->assertSame(StoryStatus::FailedMax->value, $story->refresh()->status->value);
    }

    public function test_bulk_retry_and_set_pending_skip_stories_with_purged_media(): void
    {
        $this->actingAs($this->admin());
        $media = $this->purgedMedia();

        $story = Story::create([
            'type' => 'image',
            'media_file_id' => $media->id,
            'scheduled_at' => now()->subDays(10),
            'status' => StoryStatus::FailedMax,
            'connection_revision' => 1,
        ]);

        $this->postJson(route('stories.bulk'), [
            'action' => 'retry',
            'ids' => [$story->id],
        ])->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('skipped', 1);

        $this->postJson(route('stories.bulk'), [
            'action' => 'set_status',
            'status' => 'pending',
            'ids' => [$story->id],
        ])->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('skipped', 1);

        $this->assertSame(StoryStatus::FailedMax->value, $story->refresh()->status->value);
    }

    public function test_recent_media_pickers_exclude_purged_files(): void
    {
        $this->actingAs($this->admin());
        $purged = $this->purgedMedia();

        Storage::disk('public')->put('stories/2026/10/live.jpg', 'x');
        $live = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/live.jpg',
            'original_name' => 'live.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'created_by' => $purged->created_by,
        ]);

        $this->get(route('stories.create'))->assertInertia(fn ($page) => $page
            ->where('recent_media', fn ($media) => collect($media)->pluck('id')->all() === [$live->id]));

        $this->get(route('broadcasts.create'))->assertInertia(fn ($page) => $page
            ->where('recent_media', fn ($media) => collect($media)->pluck('id')->all() === [$live->id]));
    }
}
