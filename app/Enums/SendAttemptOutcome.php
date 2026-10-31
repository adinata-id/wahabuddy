<?php

namespace App\Enums;

enum SendAttemptOutcome: string
{
    case Started = 'started';
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
