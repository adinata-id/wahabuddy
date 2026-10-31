<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_settings(): void
    {
        $this->get('/settings')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_command_does_not_reset_existing_password(): void
    {
        $this->artisan('wahabuddy:admin')
            ->expectsQuestion('Name', 'Admin')
            ->expectsQuestion('Email address', 'admin@example.com')
            ->expectsQuestion('Password', 'original-password')
            ->expectsOutputToContain('Admin account created');

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $originalHash = $user->password;

        $this->assertTrue(Hash::check('original-password', $originalHash));
        $this->assertNotNull($user->email_verified_at);

        // Re-running the command must not silently change the stored password.
        $this->artisan('wahabuddy:admin')
            ->expectsQuestion('Name', 'Admin')
            ->expectsQuestion('Email address', 'admin@example.com')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
        $this->assertDatabaseCount('users', 1);
    }
}
