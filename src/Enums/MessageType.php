<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Enums;

use RoundlyConsulting\Enums\Helpers;

enum MessageType: string
{
    use Helpers;

    case Text = 'text';
    case System = 'system';
}
