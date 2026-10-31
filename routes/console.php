<?php

use Illuminate\Support\Facades\Schedule;

// Media lifecycle (spec H.5): orphaned uploads older than 24h are removed
// weekly. Referenced media — including anything used by sending/unknown
// work — is never touched.
Schedule::command('wahabuddy:prune-orphan-media')->weekly()->mondays()->at('03:17');
