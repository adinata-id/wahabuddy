<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wahabuddy:admin
                            {--reset-password : Allow changing the password of an existing admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update the single WahaBuddy administrator account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->ask('Name', 'Admin');
        $email = $this->ask('Email address');

        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        $email = strtolower(trim($email));

        $existing = User::where('email', $email)->first();

        if ($existing !== null && ! $this->option('reset-password')) {
            $this->info("An account for {$email} already exists. Password was NOT changed.");
            $this->line('Run with --reset-password to set a new password explicitly.');

            return self::SUCCESS;
        }

        $this->line('Minimum 8 characters. Input is hidden.');

        $plainPassword = $this->validatedSecret(
            $existing !== null ? 'New password' : 'Password'
        );

        if ($plainPassword === null) {
            return self::FAILURE;
        }

        if ($existing !== null) {
            $existing->forceFill([
                'name' => $name ?? $existing->name,
                'password' => Hash::make($plainPassword),
            ])->save();

            $this->info('Admin password updated.');

            return self::SUCCESS;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name ?? 'Admin',
            'email' => $email,
            'password' => Hash::make($plainPassword),
            'email_verified_at' => now(),
        ]);
        $user->save();

        $this->info("Admin account created for {$email}.");

        return self::SUCCESS;
    }

    /**
     * Ask for a hidden password until it satisfies the minimum length.
     */
    private function validatedSecret(string $label): ?string
    {
        $attempt = 0;

        while ($attempt < 3) {
            $value = $this->secret($label);

            if ($value !== null && strlen($value) >= 8) {
                return $value;
            }

            $this->error('The password must be at least 8 characters.');
            $attempt++;
        }

        $this->error('Too many invalid attempts.');

        return null;
    }
}
