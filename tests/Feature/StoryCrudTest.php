<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\MediaFile;
use App\Models\Story;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoryCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_guest_cannot_manage_stories(): void
    {
        $this->get(route('stories.index'))->assertRedirect('/login');
        $this->post(route('stories.store'), [])->assertRedirect('/login');
    }

    public function test_store_validates_input(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('stories.store'), [
            'type' => 'text',
            'text' => str_repeat('x', 701),
            'background_color' => 'not-a-color',
            'font' => 99,
            'scheduled_at' => '2026-11-01 09:00',
        ])->assertInvalid(['text', 'background_color', 'font', 'scheduled_at']);

        $this->post(route('stories.store'), [
            'type' => 'banana',
            'scheduled_at' => '2026-11-01T09:00:00+07:00',
        ])->assertInvalid(['type']);

        // Caption limit for media stories.
        Storage::fake('public');
        $media = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/a.jpg',
            'original_name' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $this->post(route('stories.store'), [
            'type' => 'image',
            'media_file_id' => $media->id,
            'text' => str_repeat('c', 501),
            'scheduled_at' => '2026-11-01T09:00:00+07:00',
        ])->assertInvalid(['text']);

        // Image stories require media with a matching image MIME type.
        $video = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/a.mp4',
            'original_name' => 'a.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $this->post(route('stories.store'), [
            'type' => 'image',
            'media_file_id' => $video->id,
            'scheduled_at' => '2026-11-01T09:00:00+07:00',
        ])->assertInvalid(['media_file_id']);

        $this->post(route('stories.store'), [
            'type' => 'image',
            'scheduled_at' => '2026-11-01T09:00:00+07:00',
        ])->assertInvalid(['media_file_id']);

        $this->assertDatabaseCount('stories', 0);
    }

    public function test_stores_schedule_in_utc_and_renders_in_display_timezone(): void
    {
        // Pin now before the hardcoded schedule dates so they stay in the future.
        $this->travelTo(Carbon::parse('2026-10-15 12:00', 'UTC'));

        $this->actingAs($this->admin());

        $response = $this->post(route('stories.store'), [
            'type' => 'text',
            'text' => 'Good morning',
            'background_color' => '#38b42f',
            'font' => 1,
            'scheduled_at' => '2026-11-01T09:00:00+07:00',
        ]);

        $response->assertRedirect();

        $story = Story::first();
        $this->assertSame('2026-11-01 02:00:00', $story->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $story->scheduled_at->timezoneName);

        // Default display timezone Asia/Jakarta renders it back as 09:00.
        $page = $this->get(route('stories.show', $story));
        $page->assertOk();

        $props = json_decode(json_encode($page->inertiaPage()['props']), true);
        $this->assertSame('2026-11-01T02:00:00+00:00', $props['story']['scheduled_at']);
        $this->assertSame('2026-11-01 09:00', $props['story']['scheduled_at_display']);
        $this->assertSame('Asia/Jakarta', $props['story']['display_timezone']);
    }

    public function test_edit_is_limited_to_unsent_stories(): void
    {
        // Pin now before the hardcoded schedule dates so they stay in the future.
        $this->travelTo(Carbon::parse('2026-10-15 12:00', 'UTC'));

        $this->actingAs($this->admin());

        $sent = Story::create([
            'type' => 'text',
            'text' => 'already out',
            'scheduled_at' => now()->subHour(),
            'status' => StoryStatus::Sent,
            'sent_at' => now()->subHour(),
            'connection_revision' => 1,
        ]);

        $this->put(route('stories.update', $sent), [
            'type' => 'text',
            'text' => 'tampered',
            'scheduled_at' => '2026-11-01T09:00:00+07:00',
        ])->assertForbidden();

        $this->assertSame('already out', $sent->fresh()->text);

        $pending = Story::create([
            'type' => 'text',
            'text' => 'planned',
            'scheduled_at' => now()->addDay(),
            'connection_revision' => 1,
        ]);

        $this->put(route('stories.update', $pending), [
            'type' => 'text',
            'text' => 'planned better',
            'scheduled_at' => '2026-11-02T10:30:00+07:00',
        ])->assertRedirect();

        $pending = $pending->fresh();
        $this->assertSame('planned better', $pending->text);
        $this->assertSame('2026-11-02 03:30:00', $pending->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_index_paginates_and_filters(): void
    {
        $this->actingAs($this->admin());

        $base = ['connection_revision' => 1];

        Story::create($base + ['type' => 'text', 'text' => 'a', 'scheduled_at' => '2026-11-01T02:00:00+00:00', 'status' => StoryStatus::Pending]);
        Story::create($base + ['type' => 'image', 'scheduled_at' => '2026-11-02T02:00:00+00:00', 'status' => StoryStatus::Sent]);
        Story::create($base + ['type' => 'text', 'text' => 'b', 'scheduled_at' => '2026-12-01T02:00:00+00:00', 'status' => StoryStatus::Failed]);

        $page = $this->get(route('stories.index', ['status' => 'pending']));
        $page->assertOk();
        $props = json_decode(json_encode($page->inertiaPage()['props']), true);
        $this->assertCount(1, $props['stories']['data']);
        $this->assertSame('pending', $props['stories']['data'][0]['status']);

        $page = $this->get(route('stories.index', ['type' => 'text']));
        $props = json_decode(json_encode($page->inertiaPage()['props']), true);
        $this->assertCount(2, $props['stories']['data']);

        $page = $this->get(route('stories.index', ['from' => '2026-11-01', 'to' => '2026-11-30']));
        $props = json_decode(json_encode($page->inertiaPage()['props']), true);
        $this->assertCount(2, $props['stories']['data']);
    }
}
