<?php

namespace Tests\Feature;

use App\Enums\BroadcastStatus;
use App\Enums\BroadcastType;
use App\Enums\RecipientStatus;
use App\Models\Broadcast;
use App\Models\User;
use App\Models\WahaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
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

    public function test_guests_cannot_manage_broadcasts(): void
    {
        $this->get(route('broadcasts.index'))->assertRedirect('/login');
        $this->post(route('broadcasts.store'), [])->assertRedirect('/login');
    }

    public function test_store_validates_draft(): void
    {
        $this->actingAs($this->admin());

        // Unsupported placeholder rejected at validation time.
        $this->post(route('broadcasts.store'), [
            'name' => 'X',
            'type' => 'text',
            'template_text' => 'Hi {firstname}!',
        ])->assertInvalid(['template_text']);

        // Image broadcasts require valid image media.
        $this->post(route('broadcasts.store'), [
            'name' => 'X',
            'type' => 'image',
            'template_text' => 'promo',
            'media_file_id' => 999999,
        ])->assertInvalid(['media_file_id']);

        $this->assertDatabaseCount('broadcasts', 0);
    }

    public function test_queue_requires_valid_draft_and_pauses_recipients_check(): void
    {
        $this->actingAs($this->admin());
        Queue::fake();

        // A draft without recipients cannot be queued.
        $empty = $this->makeBroadcast();
        $this->post(route('broadcasts.queue', $empty), [
            'recipients' => [['chat_id' => 'not-valid']],
        ])->assertInvalid(['recipients']);

        // Local numbers are rejected with an explanation at queue time.
        $this->post(route('broadcasts.queue', $empty), [
            'recipients' => [['chat_id' => '081234567890']],
        ])->assertInvalid(['recipients.0.chat_id']);

        $this->assertDatabaseHas('broadcasts', ['id' => $empty->id, 'status' => 'draft']);
    }

    public function test_queued_campaign_payload_is_immutable(): void
    {
        $this->actingAs($this->admin());
        Queue::fake();

        WahaConnection::singleton()->forceFill(['base_url' => 'http://waha.test', 'session' => 'default', 'revision' => 1, 'last_test_status' => 'working'])->save();

        $broadcast = $this->makeBroadcast();
        $this->post(route('broadcasts.queue', $broadcast), [
            'recipients' => [
                ['chat_id' => '628111111111@c.us', 'name' => 'Andi'],
                ['chat_id' => '120363021234567890@g.us', 'name' => 'Team'],
            ],
        ])->assertRedirect();

        // (debug block removed)

        $this->assertSame('queued', $broadcast->fresh()->status->value);

        // Editing a queued campaign is refused.
        $this->put(route('broadcasts.update', $broadcast), [
            'name' => 'Tampered',
            'type' => 'text',
            'template_text' => 'changed',
        ])->assertForbidden();

        $this->assertSame('Hi {name}!', $broadcast->fresh()->template_text);
    }

    public function test_unauthorized_action_and_pause_resume_cancel_routes(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running]);
        $this->post(route('broadcasts.pause', $broadcast))->assertRedirect('/email/verify');
    }

    public function test_show_renders_progress_and_recipient_states(): void
    {
        $this->actingAs($this->admin());

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Running]);
        $broadcast->recipients()->createMany([
            ['chat_id' => '1@c.us', 'rendered_text' => 'Hi Andi!', 'status' => RecipientStatus::Sent, 'sent_at' => now()],
            ['chat_id' => '2@c.us', 'rendered_text' => 'Hi there!', 'status' => RecipientStatus::Pending],
            ['chat_id' => '3@c.us', 'rendered_text' => 'Hi Budi!', 'status' => RecipientStatus::Unknown],
            ['chat_id' => '4@c.us', 'rendered_text' => 'Hi Citra!', 'status' => RecipientStatus::Failed],
        ]);

        $page = $this->get(route('broadcasts.show', $broadcast));
        $page->assertOk();

        $props = json_decode(json_encode($page->inertiaPage()['props']), true);

        $this->assertSame(1, $props['broadcast']['counts']['sent']);
        $this->assertSame(1, $props['broadcast']['counts']['pending']);
        $this->assertSame(1, $props['broadcast']['counts']['unknown']);
        $this->assertSame(1, $props['broadcast']['counts']['failed']);
        $this->assertSame('Hi Andi!', $props['recipients']['data'][0]['rendered_text'] ?? $props['recipients']['data']['0']['rendered_text'] ?? null);
    }

    public function test_resolve_recipient_does_not_auto_resume_campaign(): void
    {
        $this->actingAs($this->admin());

        $broadcast = $this->makeBroadcast(['status' => BroadcastStatus::Paused]);
        $recipient = $broadcast->recipients()->create([
            'chat_id' => '1@c.us',
            'rendered_text' => 'x',
            'status' => RecipientStatus::Unknown,
        ]);

        $this->post(route('broadcasts.recipients.resolve', [$broadcast, $recipient]), [
            'decision' => 'mark_sent',
            'note' => 'Verified on the phone.',
        ])->assertRedirect();

        $this->assertSame('sent', $recipient->fresh()->status->value);
        $this->assertSame('paused', $broadcast->fresh()->status->value, 'Resolution must not auto-resume the campaign.');

        // A note is mandatory.
        $other = $broadcast->recipients()->create([
            'chat_id' => '2@c.us',
            'rendered_text' => 'x',
            'status' => RecipientStatus::Unknown,
        ]);

        $this->post(route('broadcasts.recipients.resolve', [$broadcast, $other]), [
            'decision' => 'mark_sent',
            'note' => '',
        ])->assertInvalid(['note']);

        $this->assertSame('unknown', $other->fresh()->status->value);
    }
}
