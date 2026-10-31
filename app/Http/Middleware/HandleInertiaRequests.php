<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use App\Models\WahaConnection;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.org/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'waha' => $this->wahaStatus(),
            'cleanup_summary' => $request->session()->get('cleanup_summary'),
        ];
    }

    /**
     * Connection status + display timezone for the top bar. Sanitized: no
     * secrets, only the configured/last-test summary.
     *
     * @return array<string, mixed>
     */
    private function wahaStatus(): array
    {
        $connection = WahaConnection::singleton();
        $settings = AppSetting::singleton();

        return [
            'configured' => $connection->isConfigured(),
            'sessionName' => $connection->session,
            'lastTestStatus' => $connection->last_test_status,
            'lastTestAt' => $connection->last_test_at?->toAtomString(),
            'displayTimezone' => $settings->display_timezone,
        ];
    }
}
