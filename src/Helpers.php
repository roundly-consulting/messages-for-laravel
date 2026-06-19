<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\MessagingService;

if (! function_exists('messaging')) {
    function messaging(): MessagingService
    {
        return resolve(MessagingService::class);
    }
}
