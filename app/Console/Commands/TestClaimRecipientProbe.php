<?php

namespace App\Console\Commands;

use App\Actions\Broadcasts\ClaimRecipient;
use App\Actions\Broadcasts\CompleteRecipient;
use App\Services\Broadcasts\PacingClock;
use App\Services\Sending\SessionSendGate;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\DTO\SendResult;
use Illuminate\Console\Command;

/**
 * Test-only probe that claims (and optionally completes) one broadcast
 * recipient in an isolated process — the two-worker race proof for Task 7.
 * Never call in production.
 */
class TestClaimRecipientProbe extends Command
{
    protected $signature = 'waha:test-claim-recipient
                            {broadcast : Broadcast id}
                            {--complete= : "token:recipientId" to complete as sent}
                            {--delay=0 : Seconds to wait before claiming}';

    protected $description = '[testing] Claim one broadcast recipient in an isolated process';

    protected $hidden = true;

    public function handle(
        ClaimRecipient $claim,
        CompleteRecipient $complete,
        ConnectionRepository $connections,
        PacingClock $clock,
    ): int {
        $delay = (float) $this->option('delay');
        if ($delay > 0) {
            usleep((int) ($delay * 1_000_000));
        }

        $broadcastId = (int) $this->argument('broadcast');
        $completeSpec = $this->option('complete');

        if (is_string($completeSpec) && str_contains($completeSpec, ':')) {
            [$token, $recipientId] = explode(':', $completeSpec, 2);
            $ok = $complete->handle((int) $recipientId, $token, new SendResult(messageId: 'bc-msg', keyId: 'bc-key'));
            $this->line($ok ? 'COMPLETED' : 'REJECTED');

            return self::SUCCESS;
        }

        $claimed = $claim->handle($broadcastId, $connections->snapshot());

        if ($claimed === null) {
            $this->line('NONE');

            return self::SUCCESS;
        }

        $this->line($claimed['token'].':'.$claimed['recipient']->id);

        // Simulate the post-completion pacing write exactly like the real job.
        $delay = $clock->randomDelaySeconds();
        $claimed['recipient']->broadcast->forceFill([
            'next_send_at' => now()->addSeconds($delay),
        ])->save();
        app(SessionSendGate::class)->recordPacing(
            $connections->snapshot(),
            $delay,
        );

        return self::SUCCESS;
    }
}
