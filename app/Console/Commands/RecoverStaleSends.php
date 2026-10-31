<?php

namespace App\Console\Commands;

use App\Enums\SendAttemptOutcome;
use App\Enums\StoryStatus;
use App\Models\SendAttempt;
use App\Models\Story;
use Illuminate\Console\Command;

/**
 * Recovers stories stuck in `sending` for longer than the stale window
 * (15 minutes default). Conservative by contract (spec G2): they become
 * `unknown`, never pending — a remote acceptance cannot be ruled out, and
 * there is no local way to guarantee exactly-once for a lost non-idempotent
 * HTTP POST. Stored send proof still heals rows to `sent` first.
 */
class RecoverStaleSends extends Command
{
    protected $signature = 'waha:recover-stale-sends {--minutes= : Stale threshold overriding config}';

    protected $description = 'Move stale sending stories to unknown (proof heals to sent)';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('wahabuddy.stories.stale_sending_minutes', 15));
        $staleBefore = now()->subMinutes(max(1, $minutes));

        $recovered = 0;

        Story::query()
            ->where('status', StoryStatus::Sending->value)
            ->where('sending_started_at', '<', $staleBefore)
            ->whereNotNull('attempt_token')
            ->chunkById(50, function ($chunk) use (&$recovered): void {
                foreach ($chunk as $story) {
                    $token = $story->attempt_token;

                    // Stored success proof wins over the unknown fallback.
                    $proof = SendAttempt::query()
                        ->where('attempt_token', $token)
                        ->where('outcome', SendAttemptOutcome::Sent)
                        ->first();

                    if ($proof !== null) {
                        $updated = Story::query()
                            ->whereKey($story->id)
                            ->where('attempt_token', $token)
                            ->update([
                                'status' => StoryStatus::Sent->value,
                                'sent_at' => $proof->finished_at ?? now(),
                                'waha_message_id' => $proof->message_id,
                                'waha_key_id' => $proof->key_id,
                                'attempt_token' => null,
                            ]);

                        if ($updated > 0) {
                            $story->logs()->create([
                                'event' => 'healed',
                                'message' => 'Recovered to sent from stored send proof.',
                            ]);
                            $recovered++;
                        }

                        continue;
                    }

                    $updated = Story::query()
                        ->whereKey($story->id)
                        ->where('attempt_token', $token)
                        ->where('status', StoryStatus::Sending->value)
                        ->update([
                            'status' => StoryStatus::Unknown->value,
                            'last_error_code' => 'stale_sending_recovered',
                            'last_error' => 'No completion within the stale window; remote acceptance could not be ruled out.',
                            'attempt_token' => null,
                        ]);

                    if ($updated > 0) {
                        SendAttempt::query()
                            ->where('attempt_token', $token)
                            ->whereNull('finished_at')
                            ->update([
                                'outcome' => SendAttemptOutcome::Unknown,
                                'finished_at' => now(),
                                'error_code' => 'stale_sending_recovered',
                                'error' => 'Worker did not complete within the stale window.',
                            ]);

                        $story->logs()->create([
                            'event' => 'recovered_unknown',
                            'attempt_token' => $token,
                            'message' => 'Stale sending row moved to unknown; not auto-retried.',
                        ]);
                        $recovered++;
                    }
                }
            });

        if ($recovered > 0) {
            $this->info("Recovered {$recovered} stale send(s).");
        }

        return self::SUCCESS;
    }
}
