<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\MessagingService;

it('returns MessagingService service', function () {
    expect(messaging())->toBeInstanceOf(MessagingService::class);
});
