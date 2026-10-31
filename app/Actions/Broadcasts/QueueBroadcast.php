<?php

namespace App\Actions\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Enums\RecipientStatus;
use App\Jobs\ProcessBroadcastJob;
use App\Models\Broadcast;
use App\Services\Broadcasts\TemplateRenderer;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Support\Facades\DB;

/**
 * Queues a draft campaign (spec I/M): snapshots chat_id, name and rendered
 * text per recipient at queue time, dedupes via the unique constraint (a
 * repeated queue request can never double recipients), stores the target
 * revision, and dispatches the first processing job after commit.
 */
final class QueueBroadcast
{
    public function __construct(private readonly ConnectionRepository $connections) {}

    /**
     * @param  array<int, array{chat_id: string, name?: ?string}>  $recipients
     */
    public function handle(Broadcast $broadcast, array $recipients): void
    {
        if (in_array($broadcast->status, [BroadcastStatus::Completed, BroadcastStatus::Canceled], true)) {
            throw new \InvalidArgumentException('This campaign already finished and cannot be queued.');
        }

        if ($recipients === []) {
            throw new \InvalidArgumentException('A campaign needs at least one allowed recipient.');
        }

        if ($broadcast->status !== BroadcastStatus::Draft) {
            // Repeated queue request: only insert still-missing recipients.
            // The unique constraint guarantees nothing is ever duplicated.
            $this->insertRecipients($broadcast, $recipients);

            return;
        }

        $connection = $this->connections->current();
        $revision = $connection->revision;

        DB::transaction(function () use ($broadcast, $recipients, $revision): void {
            $broadcast->forceFill([
                'status' => BroadcastStatus::Queued,
                'connection_revision' => $revision,
                'next_send_at' => now(),
                'started_at' => now(),
                'finished_at' => null,
            ])->save();

            $inserted = $this->insertRecipients($broadcast, $recipients);

            $broadcast->logs()->create([
                'event' => 'queued',
                'message' => 'Campaign queued with '.$inserted.' recipient(s).',
                'context' => ['connection_revision' => $revision, 'recipients' => $inserted],
            ]);
        });

        DB::afterCommit(fn () => ProcessBroadcastJob::dispatch($broadcast->id));
    }

    /**
     * Insert still-missing recipient snapshots; returns the number added.
     *
     * @param  array<int, array{chat_id: string, name?: ?string}>  $recipients
     */
    private function insertRecipients(Broadcast $broadcast, array $recipients): int
    {
        $rows = [];
        foreach ($recipients as $recipient) {
            // The FIRST snapshot of a duplicated chat id wins.
            if (isset($rows[$recipient['chat_id']])) {
                continue;
            }

            $rows[$recipient['chat_id']] = [
                'broadcast_id' => $broadcast->id,
                'chat_id' => $recipient['chat_id'],
                'name' => $recipient['name'] ?? null,
                'rendered_text' => TemplateRenderer::render(
                    $broadcast->template_text,
                    $recipient['name'] ?? null,
                ),
                'status' => RecipientStatus::Pending->value,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $chunks = array_values($rows);
        foreach (array_chunk($chunks, 250) as $chunk) {
            $broadcast->recipients()->insertOrIgnore($chunk);
        }

        return count($chunks);
    }
}
