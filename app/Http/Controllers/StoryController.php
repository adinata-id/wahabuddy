<?php

namespace App\Http\Controllers;

use App\Enums\StoryStatus;
use App\Http\Requests\StoreStoryRequest;
use App\Models\AppSetting;
use App\Models\MediaFile;
use App\Models\Story;
use App\Services\Media\MediaUrlResolver;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class StoryController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(private readonly MediaUrlResolver $urls) {}

    /**
     * Story list with pagination and date/status/type filters.
     */
    public function index(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $timezone = AppSetting::singleton()->display_timezone;

        $query = Story::query()->orderBy('scheduled_at', 'desc');

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['type'] !== null) {
            $query->where('type', $filters['type']);
        }

        if ($filters['from'] !== null) {
            $query->where('scheduled_at', '>=', Carbon::parse($filters['from'], $timezone)->startOfDay()->utc());
        }

        if ($filters['to'] !== null) {
            $query->where('scheduled_at', '<=', Carbon::parse($filters['to'], $timezone)->endOfDay()->utc());
        }

        $stories = $query->paginate(self::PER_PAGE)->withQueryString();

        $media = MediaFile::whereIn('id', $stories->getCollection()->pluck('media_file_id')->filter())->get()->keyBy('id');

        return Inertia::render('stories/index', [
            'stories' => [
                'data' => $stories->getCollection()->map(fn (Story $story) => $this->storyRow($story, $timezone, $media))->values()->all(),
                'current_page' => $stories->currentPage(),
                'last_page' => $stories->lastPage(),
                'total' => $stories->total(),
                'per_page' => $stories->perPage(),
            ],
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('stories/create', $this->formProps(null));
    }

    public function store(StoreStoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $connection = app(ConnectionRepository::class)->current();

        $story = Story::create([
            'type' => $validated['type'],
            'media_file_id' => $validated['media_file_id'] ?? null,
            'text' => $validated['text'] ?? null,
            'background_color' => $validated['background_color'] ?? '#38b42f',
            'font' => $validated['font'] ?? 1,
            'scheduled_at' => $request->parseScheduledAt(),
            'status' => StoryStatus::Pending,
            'attempts' => 0,
            'connection_revision' => $connection->revision,
        ]);

        $story->logs()->create([
            'event' => 'created',
            'message' => 'Story scheduled.',
            'context' => [
                'type' => $story->type->value,
                'scheduled_at' => $story->scheduled_at->toAtomString(),
                'connection_revision' => $story->connection_revision,
            ],
        ]);

        return redirect()->route('stories.show', $story);
    }

    public function show(Story $story, Request $request): Response
    {
        $timezone = AppSetting::singleton()->display_timezone;

        $logs = $story->logs()->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'logs_page')->withQueryString();

        return Inertia::render('stories/show', [
            'story' => $this->storyDetail($story, $timezone),
            'logs' => [
                'data' => $logs->getCollection()->map(fn ($log) => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'message' => $log->message,
                    'attempt_token' => $log->attempt_token,
                    'context' => $log->context,
                    'created_at' => $log->created_at?->toAtomString(),
                    'created_at_display' => $log->created_at?->timezone($timezone)->format('Y-m-d H:i:s'),
                ])->values()->all(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function edit(Story $story): Response
    {
        abort_unless($story->isEditable(), 403, 'Only pending or failed stories that were never sent can be edited.');

        return Inertia::render('stories/edit', $this->formProps($story));
    }

    public function update(StoreStoryRequest $request, Story $story): RedirectResponse
    {
        abort_unless($story->isEditable(), 403, 'Only pending or failed stories that were never sent can be edited.');

        $validated = $request->validated();

        $story->fill([
            'type' => $validated['type'],
            'media_file_id' => $validated['media_file_id'] ?? null,
            'text' => $validated['text'] ?? null,
            'background_color' => $validated['background_color'] ?? '#38b42f',
            'font' => $validated['font'] ?? 1,
            'scheduled_at' => $request->parseScheduledAt(),
        ]);
        $story->save();

        $story->logs()->create([
            'event' => 'updated',
            'message' => 'Story edited before send.',
            'context' => ['scheduled_at' => $story->scheduled_at->toAtomString()],
        ]);

        return redirect()->route('stories.show', $story);
    }

    /**
     * Local record deletion (remote deletion is a separate action). Blocked
     * while sending or unknown: the attempt evidence must survive.
     */
    public function destroy(Story $story): RedirectResponse
    {
        if (in_array($story->status, [StoryStatus::Sending, StoryStatus::Unknown], true)) {
            return back()->withErrors(['story' => 'Stories that are sending or in unknown state cannot be deleted; resolve them first.']);
        }

        $story->logs()->create([
            'event' => 'deleted_local',
            'message' => 'Local story record deleted.',
        ]);
        $story->delete();

        return redirect()->route('stories.index');
    }

    /**
     * @return array{status: ?string, type: ?string, from: ?string, to: ?string}
     */
    private function validatedFilters(Request $request): array
    {
        return [
            'status' => in_array($request->query('status'), array_column(StoryStatus::cases(), 'value'), true) ? $request->query('status') : null,
            'type' => in_array($request->query('type'), ['text', 'image', 'video'], true) ? $request->query('type') : null,
            'from' => $request->query('from') !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('from')) ? (string) $request->query('from') : null,
            'to' => $request->query('to') !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to')) ? (string) $request->query('to') : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(?Story $story): array
    {
        $timezone = AppSetting::singleton()->display_timezone;

        $recentMedia = MediaFile::whereNull('purged_at')->orderByDesc('created_at')->limit(30)->get()->map(fn (MediaFile $media) => [
            'id' => $media->id,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'video_codec' => $media->video_codec,
            'url' => $this->urls->forPreview($media),
        ]);

        return [
            'story' => $story === null ? null : $this->storyDetail($story, $timezone),
            'recent_media' => $recentMedia,
            'display_timezone' => $timezone,
        ];
    }

    /**
     * @param  Collection<int, MediaFile>  $media
     * @return array<string, mixed>
     */
    private function storyRow(Story $story, string $timezone, $media): array
    {
        $file = $story->media_file_id !== null ? $media->get($story->media_file_id) : null;

        return [
            'id' => $story->id,
            'type' => $story->type->value,
            'text' => $story->text !== null && $story->text !== '' ? mb_substr($story->text, 0, 80) : null,
            'status' => $story->status->value,
            'attempts' => $story->attempts,
            'scheduled_at' => $story->scheduled_at->toAtomString(),
            'scheduled_at_display' => $story->scheduled_at->timezone($timezone)->format('Y-m-d H:i'),
            'display_timezone' => $timezone,
            'last_error' => $story->last_error,
            'thumbnail_url' => $file !== null && str_starts_with($file->mime_type, 'image/') ? $this->urls->forPreview($file) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storyDetail(Story $story, string $timezone): array
    {
        $file = $story->mediaFile;

        return [
            'id' => $story->id,
            'type' => $story->type->value,
            'text' => $story->text,
            'background_color' => $story->background_color,
            'font' => $story->font,
            'status' => $story->status->value,
            'attempts' => $story->attempts,
            'scheduled_at' => $story->scheduled_at->toAtomString(),
            'scheduled_at_display' => $story->scheduled_at->timezone($timezone)->format('Y-m-d H:i'),
            'display_timezone' => $timezone,
            'connection_revision' => $story->connection_revision,
            'waha_message_id' => $story->waha_message_id,
            'waha_key_id' => $story->waha_key_id,
            'last_error_code' => $story->last_error_code,
            'last_error' => $story->last_error,
            'sent_at' => $story->sent_at?->toAtomString(),
            'sent_at_display' => $story->sent_at?->timezone($timezone)->format('Y-m-d H:i:s'),
            'deleted_from_waha_at' => $story->deleted_from_waha_at?->toAtomString(),
            'editable' => $story->isEditable(),
            'media' => $file !== null ? [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'video_codec' => $file->video_codec,
                'url' => $this->urls->forPreview($file),
            ] : null,
        ];
    }
}
