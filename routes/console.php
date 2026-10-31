<?php

use App\Jobs\RecordWorkerHeartbeatJob;
use Illuminate\Support\Facades\Schedule;

// Story dispatcher: fast, no HTTP, overlapping runs suppressed (spec G2).
// A system cron still has to trigger `php artisan schedule:run` every minute.
Schedule::command('waha:send-stories')->everyMinute()->withoutOverlapping();

// Stale `sending` rows become unknown (proof heals to sent) every 5 minutes.
Schedule::command('waha:recover-stale-sends')->everyFiveMinutes()->withoutOverlapping();

// Broadcast sweeper: campaigns that lost their follow-up job get re-queued.
Schedule::command('waha:recover-broadcasts')->everyFiveMinutes()->withoutOverlapping();

// Worker heartbeat so the dashboard can report queue-worker liveness.
Schedule::job(new RecordWorkerHeartbeatJob)->everyFiveMinutes();

// Media lifecycle (spec H.5): orphaned uploads older than 24h are removed
// weekly. Referenced media — including anything used by sending/unknown
// work — is never touched.
Schedule::command('wahabuddy:prune-orphan-media')->weekly()->mondays()->at('03:17');

// Log retention (spec G.2): keep the newest 10,000 story log rows.
Schedule::command('waha:prune-story-logs')->weekly()->mondays()->at('03:33');
