<?php

namespace App\Enums;

enum StoryType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
}
