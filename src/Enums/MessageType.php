<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Enums;

enum MessageType: string
{
    case Text = 'text';
    case System = 'system';
}
