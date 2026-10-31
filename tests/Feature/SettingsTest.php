<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WahaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_secrets_are_encrypted_and_never_in_props(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('settings.connection.update'), [
            'base_url' => 'http://waha.example.test/',
            'session' => 'default',
            'api_key' => 'waha-secret-abc123',
            'basic_username' => 'proxyuser',
            'basic_password' => 'basic-pass-xyz',
        ])->assertRedirect();

        $raw = DB::table('waha_connections')->first();

        $this->assertNotNull($raw);
        $this->assertSame('http://waha.example.test', $raw->base_url);
        $this->assertStringNotContainsString('waha-secret-abc123', $raw->api_key);
        $this->assertStringNotContainsString('basic-pass-xyz', $raw->basic_password);

        $response = $this->get(route('settings.index'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('waha-secret-abc123', $content);
        $this->assertStringNotContainsString('basic-pass-xyz', $content);

        $response->assertInertia(fn ($page) => $page
            ->component('settings/index')
            ->where('connection.has_api_key', true)
            ->where('connection.has_basic_password', true)
            ->where('connection.base_url', 'http://waha.example.test')
            ->where('connection.basic_username', 'proxyuser')
        );
    }

    public function test_blank_secret_preserves_value(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('settings.connection.update'), [
            'base_url' => 'http://waha.example.test',
            'session' => 'default',
            'api_key' => 'first-key',
            'basic_username' => 'u',
            'basic_password' => 'first-basic',
        ])->assertRedirect();

        $connection = WahaConnection::singleton();
        $revision = $connection->revision;

        $this->assertSame('first-key', $connection->api_key);
        $this->assertSame('first-basic', $connection->basic_password);

        // Blank/omitted secret fields must keep the stored value and not bump revision.
        $this->put(route('settings.connection.update'), [
            'base_url' => 'http://waha.example.test',
            'session' => 'default',
            'api_key' => '',
            'basic_username' => 'u',
            'basic_password' => '',
        ])->assertRedirect();

        $connection = $connection->fresh();
        $this->assertSame('first-key', $connection->api_key);
        $this->assertSame('first-basic', $connection->basic_password);
        $this->assertSame($revision, $connection->revision);
    }

    public function test_connection_test_does_not_persist_unsaved_form(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('settings.connection.update'), [
            'base_url' => 'http://saved.example.test',
            'session' => 'default',
            'api_key' => 'saved-key',
        ])->assertRedirect();

        Http::fake([
            'unsaved.example.test/api/sessions/default' => Http::response([
                'name' => 'default',
                'status' => 'WORKING',
            ], 200),
            'saved.example.test/api/sessions/default' => Http::response([
                'name' => 'default',
                'status' => 'WORKING',
            ], 200),
        ]);

        $this->postJson(route('settings.connection.test'), [
            'base_url' => 'http://unsaved.example.test',
            'session' => 'default',
            'api_key' => 'unsaved-key',
        ])->assertOk()->assertJson(['working' => true]);

        $connection = WahaConnection::singleton();
        $this->assertSame('http://saved.example.test', $connection->base_url);
        $this->assertSame('saved-key', $connection->api_key);
        $this->assertNull($connection->last_test_at, 'Unsaved-config test result must not be persisted.');

        // Testing the currently saved configuration persists the sanitized result.
        $this->postJson(route('settings.connection.test'), [])->assertOk();

        $connection = $connection->fresh();
        $this->assertNotNull($connection->last_test_at);
        $this->assertSame('working', $connection->last_test_status);
        $this->assertStringNotContainsString('saved-key', (string) $connection->last_test_error);
    }

    public function test_revision_changes_hold_old_work(): void
    {
        $this->actingAs($this->admin());

        $save = fn (array $overrides) => $this->put(route('settings.connection.update'), array_merge([
            'base_url' => 'http://waha.example.test',
            'session' => 'default',
            'api_key' => 'key-one',
        ], $overrides))->assertRedirect();

        $save([]);
        $this->assertSame(1, WahaConnection::singleton()->revision);

        // Identical effective target: revision must not change.
        $save(['api_key' => 'key-one']);
        $this->assertSame(1, WahaConnection::singleton()->revision);

        // Blank key resolves to the stored key: still the same target.
        $save(['api_key' => '']);
        $this->assertSame(1, WahaConnection::singleton()->revision);

        // Session change bumps revision.
        $save(['session' => 'second']);
        $this->assertSame(2, WahaConnection::singleton()->revision);

        // Host change bumps revision.
        $save(['base_url' => 'http://other.example.test', 'session' => 'second']);
        $this->assertSame(3, WahaConnection::singleton()->revision);

        // Key change bumps revision.
        $save(['base_url' => 'http://other.example.test', 'session' => 'second', 'api_key' => 'key-two']);
        $this->assertSame(4, WahaConnection::singleton()->revision);
    }

    public function test_private_http_requires_explicit_configuration(): void
    {
        $this->actingAs($this->admin());

        $assertUrl = function (string $url, bool $expectedValid) {
            $this->put(route('settings.connection.update'), [
                'base_url' => $url,
                'session' => 'default',
            ]);

            $connection = WahaConnection::singleton();

            if ($expectedValid) {
                $this->assertSame(rtrim($url, '/'), $connection->base_url, "Expected [{$url}] to be accepted.");
            } else {
                $this->assertNotSame(rtrim($url, '/'), $connection->base_url, "Expected [{$url}] to be rejected.");
            }
        };

        // Metadata and link-local targets are always rejected.
        config(['wahabuddy.allow_private_networks' => true]);
        $assertUrl('http://169.254.169.254/latest/meta-data', false);
        $assertUrl('http://[fe80::1]/', false);
        $assertUrl('gopher://waha.example.test', false);
        $assertUrl('http://user:pass@waha.example.test', false);
        $assertUrl('http://waha.example.test/?query=1', false);
        $assertUrl('http://waha.example.test/#fragment', false);

        // Private networks require explicit configuration.
        config(['wahabuddy.allow_private_networks' => false]);
        $assertUrl('http://192.168.1.10:3000/', false);

        config(['wahabuddy.allow_private_networks' => true]);
        $assertUrl('http://192.168.1.10:3000/', true);

        // Reverse proxy that also guards the API is fine.
        $assertUrl('http://waha.example.test', true);
    }
}
