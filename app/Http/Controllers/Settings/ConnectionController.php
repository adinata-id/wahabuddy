<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestConnectionRequest;
use App\Http\Requests\Settings\UpdateConnectionRequest;
use App\Services\Waha\CapabilityResolver;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\WahaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ConnectionController extends Controller
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly WahaClient $client,
        private readonly CapabilityResolver $capabilities,
    ) {}

    /**
     * Save the connection target. Saving while WAHA is unreachable is allowed
     * (with a UI warning); the scheduler holds sends until a test passes.
     */
    public function update(UpdateConnectionRequest $request): RedirectResponse
    {
        $connection = $this->connections->save($request->validated());

        return back()->with([
            'status' => 'connection-updated',
            'connection' => SettingsController::connectionProps(
                $connection->refresh(),
                $this->capabilities->overview($connection->refresh()),
            ),
        ]);
    }

    /**
     * Test possibly-unsaved form values without persisting them. The sanitized
     * result is only stored when the tested config matches the saved target.
     */
    public function test(TestConnectionRequest $request): JsonResponse
    {
        $connection = $this->connections->current();
        $snapshot = $this->connections->resolveForm($request->validated());

        $check = $this->client->checkConnection($snapshot);

        $matchesSaved = $this->connections->snapshot($connection)->configHash() === $snapshot->configHash();

        $this->connections->recordTestResult(
            $connection,
            $snapshot,
            $check->working ? 'working' : 'failing',
            $check->error,
        );

        return response()->json([
            ...$check->toArray(),
            'persisted' => $matchesSaved,
        ]);
    }

    public function clearApiKey(): RedirectResponse
    {
        $this->connections->clearApiKey();

        return back()->with('status', 'api-key-cleared');
    }

    public function clearBasicAuth(): RedirectResponse
    {
        $this->connections->clearBasicAuth();

        return back()->with('status', 'basic-auth-cleared');
    }
}
