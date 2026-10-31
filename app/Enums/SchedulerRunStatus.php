<?php

namespace App\Enums;

enum SchedulerRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
