<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\Contacts\ContactSyncService;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactsController extends Controller
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly ContactSyncService $sync,
    ) {}

    /**
     * Contact list with search and pagination; opt-in state per row.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $type = in_array($request->query('type'), ['contact', 'group'], true) ? $request->query('type') : null;

        $contacts = Contact::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('chat_id', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('contacts/index', [
            'contacts' => [
                'data' => $contacts->getCollection()->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'chat_id' => $contact->chat_id,
                    'name' => $contact->name,
                    'type' => $contact->type->value,
                    'opted_in' => $contact->isOptedIn(),
                    'opted_in_at' => $contact->opted_in_at?->toAtomString(),
                    'opted_out_at' => $contact->opted_out_at?->toAtomString(),
                ])->values()->all(),
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'total' => $contacts->total(),
            ],
            'filters' => ['search' => $search, 'type' => $type],
        ]);
    }

    /**
     * Sync contacts and groups from WAHA (read-only; never sends). Never
     * flips consent: opt-in is a separate explicit action.
     */
    public function sync(Request $request): RedirectResponse|JsonResponse
    {
        $result = $this->sync->sync($this->connections->snapshot());

        $message = "Synced {$result['synced']} entries ({$result['contacts']} contacts, {$result['groups']} groups) across {$result['pages']} page(s)."
            .($result['partial_failure'] !== null ? ' '.$result['partial_failure'] : '');

        return $request->expectsJson()
            ? response()->json([...$result, 'message' => $message])
            : back()->with('status', 'contacts-synced')->with('sync_message', $message);
    }

    /**
     * Opted-in contacts for the broadcast wizard's recipient selection.
     */
    public function optedIn(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $contacts = Contact::query()
            ->whereNotNull('opted_in_at')
            ->whereNull('opted_out_at')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('chat_id', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('chat_id')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $contacts->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'chat_id' => $contact->chat_id,
                'name' => $contact->name,
                'type' => $contact->type->value,
            ])->values()->all(),
        ]);
    }

    /**
     * Explicit consent management (opting out always wins).
     */
    public function setConsent(Request $request, Contact $contact): RedirectResponse
    {
        $validated = $request->validate([
            'consent' => ['required', 'in:in,out'],
        ]);

        if ($validated['consent'] === 'in') {
            $contact->forceFill([
                'opted_in_at' => now(),
                'opted_out_at' => null,
            ])->save();
        } else {
            $contact->forceFill([
                'opted_out_at' => now(),
            ])->save();
        }

        return back()->with('status', 'consent-updated');
    }
}
