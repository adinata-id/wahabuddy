<?php

namespace App\Http\Controllers;

use App\Actions\Broadcasts\CancelBroadcast;
use App\Actions\Broadcasts\PauseBroadcast;
use App\Actions\Broadcasts\QueueBroadcast;
use App\Actions\Broadcasts\ResolveRecipient;
use App\Actions\Broadcasts\ResumeBroadcast;
use App\Enums\BroadcastStatus;
use App\Enums\RecipientStatus;
use App\Http\Requests\QueueBroadcastRequest;
use App\Http\Requests\StoreBroadcastRequest;
use App\Models\AppSetting;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\MediaFile;
use App\Services\Media\MediaUrlResolver;
use App\Services\Waha\ConnectionRepository;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastsController extends Controller
{
    public function __construct(private readonly MediaUrlResolver $urls) {}

    /**
     * Campaign list with aggregated recipient progress per campaign.
     */
    public function index(Request $request): Response
    {
        $timezone = AppSetting::singleton()->display_timezone;

        $broadcasts = Broadcast::query()
            ->withCount([
                'recipients as sent_count' => fn ($q) => $q->where('status', RecipientStatus::Sent),
                'recipients as pending_count' => fn ($q) => $q->where('status', RecipientStatus::Pending),
                'recipients as sending_count' => fn ($q) => $q->where('status', RecipientStatus::Sending),
                'recipients as failed_count' => fn ($q) => $q->whereIn('status', [RecipientStatus::Failed, RecipientStatus::FailedMax]),
                'recipients as unknown_count' => fn ($q) => $q->where('status', RecipientStatus::Unknown),
                'recipients as canceled_count' => fn ($q) => $q->where('status', RecipientStatus::Canceled),
            ])
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('broadcasts/index', [
            'broadcasts' => [
                'data' => $broadcasts->getCollection()->map(fn (Broadcast $broadcast) => [
                    'id' => $broadcast->id,
                    'name' => $broadcast->name,
                    'type' => $broadcast->type->value,
                    'status' => $broadcast->status->value,
                    'created_at_display' => Carbon::parse($broadcast->created_at)->timezone($timezone)->format('Y-m-d H:i'),
                    'started_at_display' => $broadcast->started_at !== null ? Carbon::parse($broadcast->started_at)->timezone($timezone)->format('Y-m-d H:i') : null,
                    'finished_at_display' => $broadcast->finished_at !== null ? Carbon::parse($broadcast->finished_at)->timezone($timezone)->format('Y-m-d H:i') : null,
                    'counts' => [
                        'sent' => (int) $broadcast->getAttribute('sent_count'),
                        'pending' => (int) $broadcast->getAttribute('pending_count'),
                        'sending' => (int) $broadcast->getAttribute('sending_count'),
                        'failed' => (int) $broadcast->getAttribute('failed_count'),
                        'unknown' => (int) $broadcast->getAttribute('unknown_count'),
                        'canceled' => (int) $broadcast->getAttribute('canceled_count'),
                        'total' => (int) $broadcast->getAttribute('sent_count') + (int) $broadcast->getAttribute('pending_count') + (int) $broadcast->getAttribute('sending_count')
                            + (int) $broadcast->getAttribute('failed_count') + (int) $broadcast->getAttribute('unknown_count') + (int) $broadcast->getAttribute('canceled_count'),
                    ],
                ])->values()->all(),
                'current_page' => $broadcasts->currentPage(),
                'last_page' => $broadcasts->lastPage(),
                'total' => $broadcasts->total(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('broadcasts/create', [
            'recent_media' => MediaFile::whereNull('purged_at')->orderByDesc('created_at')->limit(30)->get()
                ->map(fn (MediaFile $media) => [
                    'id' => $media->id,
                    'original_name' => $media->original_name,
                    'mime_type' => $media->mime_type,
                    'video_codec' => $media->video_codec,
                    'url' => $this->urls->forPreview($media),
                ]),
        ]);
    }

    public function store(StoreBroadcastRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $broadcast = Broadcast::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'template_text' => $validated['template_text'],
            'media_file_id' => $validated['media_file_id'] ?? null,
            'status' => BroadcastStatus::Draft,
            'connection_revision' => app(ConnectionRepository::class)->current()->revision,
            'batch_count' => 0,
        ]);

        return redirect()->route('broadcasts.show', $broadcast);
    }

    /**
     * Draft editing (queued campaigns are immutable).
     */
    public function edit(Broadcast $broadcast): Response
    {
        abort_unless($broadcast->status === BroadcastStatus::Draft, 403, 'Queued campaigns are immutable; duplicate the draft instead.');

        return Inertia::render('broadcasts/edit', [
            'broadcast' => [
                'id' => $broadcast->id,
                'name' => $broadcast->name,
                'type' => $broadcast->type->value,
                'template_text' => $broadcast->template_text,
                'media_file_id' => $broadcast->media_file_id,
                'media' => $broadcast->mediaFile !== null ? [
                    'url' => $this->urls->forPreview($broadcast->mediaFile),
                    'original_name' => $broadcast->mediaFile->original_name,
                ] : null,
            ],
            'recent_media' => MediaFile::whereNull('purged_at')->orderByDesc('created_at')->limit(30)->get()
                ->map(fn (MediaFile $media) => [
                    'id' => $media->id,
                    'original_name' => $media->original_name,
                    'mime_type' => $media->mime_type,
                    'video_codec' => $media->video_codec,
                    'url' => $this->urls->forPreview($media),
                ]),
        ]);
    }

    /**
     * Campaign detail: recipient tracking table + event log, per state.
     */
    public function show(Broadcast $broadcast, Request $request): Response
    {
        $timezone = AppSetting::singleton()->display_timezone;

        $status = in_array($request->query('status'), ['pending', 'sending', 'sent', 'failed', 'failed_max', 'unknown', 'canceled'], true)
            ? $request->query('status') : null;

        $recipients = $broadcast->recipients()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderBy('id')
            ->paginate(20, ['*'], 'recipients_page')
            ->withQueryString();

        $logs = $broadcast->logs()->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();

        $counts = $broadcast->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('broadcasts/show', [
            'broadcast' => [
                'id' => $broadcast->id,
                'name' => $broadcast->name,
                'type' => $broadcast->type->value,
                'template_text' => $broadcast->template_text,
                'status' => $broadcast->status->value,
                'editable' => $broadcast->status === BroadcastStatus::Draft,
                'pausable' => in_array($broadcast->status, [BroadcastStatus::Queued, BroadcastStatus::Running], true),
                'resumable' => $broadcast->status === BroadcastStatus::Paused,
                'cancellable' => ! in_array($broadcast->status, [BroadcastStatus::Completed, BroadcastStatus::Canceled], true),
                'counts' => [
                    'sent' => (int) $counts->get(RecipientStatus::Sent->value, 0),
                    'pending' => (int) $counts->get(RecipientStatus::Pending->value, 0),
                    'sending' => (int) $counts->get(RecipientStatus::Sending->value, 0),
                    'failed' => (int) ($counts->get(RecipientStatus::Failed->value, 0) + $counts->get(RecipientStatus::FailedMax->value, 0)),
                    'failed_max' => (int) $counts->get(RecipientStatus::FailedMax->value, 0),
                    'unknown' => (int) $counts->get(RecipientStatus::Unknown->value, 0),
                    'canceled' => (int) $counts->get(RecipientStatus::Canceled->value, 0),
                    'total' => (int) $broadcast->recipients()->count(),
                ],
                'media' => $broadcast->mediaFile !== null ? [
                    'url' => $this->urls->forPreview($broadcast->mediaFile),
                    'original_name' => $broadcast->mediaFile->original_name,
                ] : null,
                'started_at_display' => $broadcast->started_at !== null ? Carbon::parse($broadcast->started_at)->timezone($timezone)->format('Y-m-d H:i') : null,
                'finished_at_display' => $broadcast->finished_at !== null ? Carbon::parse($broadcast->finished_at)->timezone($timezone)->format('Y-m-d H:i') : null,
            ],
            'recipients' => [
                'data' => $recipients->getCollection()->map(fn ($recipient) => [
                    'id' => $recipient->id,
                    'chat_id' => $recipient->chat_id,
                    'name' => $recipient->name,
                    'rendered_text' => $recipient->rendered_text,
                    'status' => $recipient->status->value,
                    'attempts' => $recipient->attempts,
                    'last_error' => $recipient->last_error,
                    'sent_at_display' => $recipient->sent_at?->timezone($timezone)->format('Y-m-d H:i'),
                ])->values()->all(),
                'current_page' => $recipients->currentPage(),
                'last_page' => $recipients->lastPage(),
                'total' => $recipients->total(),
            ],
            'logs' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'message' => $log->message,
                'created_at_display' => $log->created_at?->timezone($timezone)->format('Y-m-d H:i'),
            ])->values()->all(),
            'filter_status' => $status,
        ]);
    }

    /**
     * Only drafts are editable; queued campaigns are immutable snapshots.
     */
    public function update(StoreBroadcastRequest $request, Broadcast $broadcast): RedirectResponse
    {
        abort_unless($broadcast->status === BroadcastStatus::Draft, 403, 'Queued campaigns are immutable; duplicate the draft instead.');

        $validated = $request->validated();

        $broadcast->fill([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'template_text' => $validated['template_text'],
            'media_file_id' => $validated['media_file_id'] ?? null,
        ]);
        $broadcast->save();

        return redirect()->route('broadcasts.show', $broadcast);
    }

    /**
     * Queue the draft with the reviewed, deduplicated recipient set.
     */
    public function queue(QueueBroadcastRequest $request, Broadcast $broadcast, QueueBroadcast $action): RedirectResponse
    {
        abort_unless($broadcast->status === BroadcastStatus::Draft, 403, 'Only draft campaigns can be queued.');

        $validated = $request->validated();

        /** @var array<int, array{chat_id: string, name?: string|null}> $rawRecipients */
        $rawRecipients = (array) $validated['recipients'];

        $recipients = [];
        foreach ($rawRecipients as $recipient) {
            $recipients[] = [
                'chat_id' => $recipient['chat_id'],
                'name' => $recipient['name'] ?? null,
            ];
        }

        $action->handle($broadcast, $recipients);

        return redirect()->route('broadcasts.show', $broadcast);
    }

    public function pause(Broadcast $broadcast, PauseBroadcast $action): RedirectResponse
    {
        $this->runCampaignAction(fn () => $action->handle($broadcast));

        return back();
    }

    public function resume(Broadcast $broadcast, ResumeBroadcast $action): RedirectResponse
    {
        $this->runCampaignAction(fn () => $action->handle($broadcast));

        return back();
    }

    public function cancel(Broadcast $broadcast, CancelBroadcast $action): RedirectResponse
    {
        $this->runCampaignAction(fn () => $action->handle($broadcast));

        return back();
    }

    public function resolveRecipient(Request $request, Broadcast $broadcast, BroadcastRecipient $recipient, ResolveRecipient $action): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:mark_sent,retry'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        abort_unless($recipient->broadcast_id === $broadcast->id, 404);

        $this->runCampaignAction(fn () => $action->handle($recipient, $validated['decision'], $validated['note']));

        return back();
    }

    private function runCampaignAction(callable $callback): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $exception) {
            back()->withErrors(['broadcast' => $exception->getMessage()])->throwResponse();
        }
    }
}
