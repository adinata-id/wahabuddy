<?php

namespace App\Enums;

enum StoryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case FailedMax = 'failed_max';
    case Unknown = 'unknown';
    case Expired = 'expired';
    case Canceled = 'canceled';
}
