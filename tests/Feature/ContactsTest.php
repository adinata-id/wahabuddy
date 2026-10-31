<?php

namespace Tests\Feature;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\User;
use App\Models\WahaConnection;
use App\Services\Broadcasts\RecipientInput;
use App\Services\Broadcasts\TemplateRenderer;
use App\Services\Contacts\ContactSyncService;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_is_paginated_normalizes_and_preserves_groups(): void
    {
        WahaConnection::singleton()->forceFill([
            'base_url' => 'http://waha.test',
            'session' => 'default',
            'revision' => 1,
        ])->save();

        Http::fake([
            'waha.test/api/contacts/all?*' => Http::sequence()
                ->push([
                    ['chatId' => '6281111111111@c.us', 'name' => 'Andi'],
                    ['chatId' => '6282222222222@c.us', 'pushname' => 'Budi'],
                    ['chatId' => 'not-a-chat-id'],
                    ['chatId' => '6283333333333@lid'],
                ], 200)
                ->push([
                    ['id' => '12036302@g.us', 'subject' => 'Group A'],
                ], 200)
                ->push([], 200),
            'waha.test/api/default/chats' => Http::response([
                ['id' => '12036399@g.us', 'name' => 'Group B'],
                ['id' => '6281111111111@c.us'],
            ], 200),
        ]);

        $result = app(ContactSyncService::class)->sync(
            app(ConnectionRepository::class)->snapshot(),
            perPage: 2,
        );

        $this->assertSame(4, $result['synced']);
        $this->assertSame(2, $result['skipped_invalid']);
        $this->assertSame(2, $result['pages'], 'Short page (fewer than perPage) ends pagination.');

        $andi = Contact::where('chat_id', '6281111111111@c.us')->firstOrFail();
        $this->assertSame(ContactType::Contact, $andi->type);
        $this->assertSame('Andi', $andi->name);

        $group = Contact::where('chat_id', '12036399@g.us')->firstOrFail();
        $this->assertSame(ContactType::Group, $group->type);

        // Sync never opts anyone in.
        $this->assertDatabaseHas('contacts', ['chat_id' => '6281111111111@c.us', 'opted_in_at' => null]);
    }

    public function test_repeated_sync_upserts_without_duplicating(): void
    {
        WahaConnection::singleton()->forceFill([
            'base_url' => 'http://waha.test',
            'session' => 'default',
            'revision' => 1,
        ])->save();

        Http::fake([
            'waha.test/api/contacts/all?*' => Http::sequence()
                ->push([['chatId' => '6281111111111@c.us', 'name' => 'Andi']], 200)
                ->push([['chatId' => '6281111111111@c.us', 'name' => 'Andi Updated']], 200),
            'waha.test/api/default/chats' => Http::response([], 200),
        ]);

        $sync = app(ContactSyncService::class);
        $snapshot = app(ConnectionRepository::class)->snapshot();

        $sync->sync($snapshot, perPage: 2);
        $sync->sync($snapshot, perPage: 2);

        $this->assertSame(1, Contact::count());
        $this->assertSame('Andi Updated', Contact::first()->name);
    }

    public function test_recipient_input_normalizes_dedupes_and_rejects_local_numbers(): void
    {
        $parsed = RecipientInput::parse('
            +62 811-1111-111
            628111111111
            081234567890
            12345
            120363021234567890@g.us
            user@lid
            someone@example.com
            +628111111111
        ');

        $chatIds = array_column($parsed['valid'], 'chat_id');

        // Digits normalized, + optional, duplicates collapsed.
        $this->assertSame(['628111111111@c.us', '120363021234567890@g.us'], $chatIds);
        $this->assertSame('contact', $parsed['valid'][0]['type']);
        $this->assertSame('group', $parsed['valid'][1]['type']);

        // Local number (leading zero), too-short, @lid, and emails rejected.
        $this->assertContains('081234567890', $parsed['invalid']);
        $this->assertContains('12345', $parsed['invalid']);
        $this->assertContains('user@lid', $parsed['invalid']);
        $this->assertContains('someone@example.com', $parsed['invalid']);
    }

    public function test_template_renderer_only_supports_name(): void
    {
        $this->assertSame('Hi Andi!', TemplateRenderer::render('Hi {name}!', 'Andi'));
        $this->assertSame('Hi there!', TemplateRenderer::render('Hi {name}!', null));
        $this->assertSame('Hi there!', TemplateRenderer::render('Hi {name}!', '   '));
        // Single substitution pass: a name containing the placeholder is not re-expanded.
        $this->assertSame('Hi {name}!', TemplateRenderer::render('Hi {name}!', '{name}'));

        $this->expectException(\InvalidArgumentException::class);
        TemplateRenderer::validate('Hello {firstname} and {name}!');
    }

    public function test_opted_out_contacts_are_excluded_from_recipient_selection(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user);

        $optedIn = Contact::create([
            'connection_revision' => 1,
            'chat_id' => '628111111111@c.us',
            'name' => 'Andi',
            'opted_in_at' => now(),
        ]);

        $optedOut = Contact::create([
            'connection_revision' => 1,
            'chat_id' => '628222222222@c.us',
            'name' => 'Budi',
            'opted_in_at' => now()->subDay(),
            'opted_out_at' => now(),
        ]);

        // The campaign review endpoint only offers consenting recipients.
        $response = $this->getJson(route('contacts.opted-in'));
        $response->assertOk();

        $chatIds = array_column($response->json('data'), 'chat_id');
        $this->assertContains($optedIn->chat_id, $chatIds);
        $this->assertNotContains($optedOut->chat_id, $chatIds);
    }
}
