<?php

namespace App\Enums;

enum SendAttemptSubject: string
{
    case Story = 'story';
    case Recipient = 'recipient';
}
