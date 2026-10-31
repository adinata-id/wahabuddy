<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case FailedMax = 'failed_max';
    case Unknown = 'unknown';
    case Canceled = 'canceled';
}
