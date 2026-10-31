<?php

namespace App\Enums;

enum BroadcastStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Canceled = 'canceled';
}
