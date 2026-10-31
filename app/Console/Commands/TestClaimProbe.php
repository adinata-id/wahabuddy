<?php

namespace App\Console\Commands;

use App\Actions\Stories\ClaimStory;
use App\Actions\Stories\CompleteStory;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\DTO\SendResult;
use Illuminate\Console\Command;

/**
 * Test-only probe that claims (and optionally completes) a story in an
 * isolated process. Used by the concurrency integration test to prove the
 * compare-and-swap claim with real parallel workers; never call in production.
 */
class TestClaimProbe extends Command
{
    protected $signature = 'waha:test-claim
                            {story : Story id}
                            {--complete= : Attempt token to complete as sent}
                            {--delay=0 : Seconds to wait before claiming}';

    protected $description = '[testing] Claim a story in an isolated process';

    protected $hidden = true;

    public function handle(ClaimStory $claim, CompleteStory $complete, ConnectionRepository $connections): int
    {
        $delay = (float) $this->option('delay');
        if ($delay > 0) {
            usleep((int) ($delay * 1_000_000));
        }

        $storyId = (int) $this->argument('story');
        $token = $this->option('complete');

        if (is_string($token) && $token !== '') {
            $ok = $complete->handle($storyId, $token, new SendResult(messageId: 'concurrency-msg', keyId: 'concurrency-key'));
            $this->line($ok ? 'COMPLETED' : 'REJECTED');

            return self::SUCCESS;
        }

        $result = $claim->handle($storyId, $connections->snapshot());
        $this->line($result ?? 'NONE');

        return self::SUCCESS;
    }
}
