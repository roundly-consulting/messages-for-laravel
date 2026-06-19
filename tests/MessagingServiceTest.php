<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\MessagingService;
use RoundlyConsulting\Messages\Repositories\MessagesRepository;
use RoundlyConsulting\Messages\Repositories\ParticipantsRepository;
use RoundlyConsulting\Messages\Repositories\ThreadsRepository;

it('returns correct repository', function (string $method, string $expectedRepository) {
    $service = resolve(MessagingService::class);

    expect($service->$method())->toBeInstanceOf($expectedRepository);
})->with([
    ['messages', MessagesRepository::class],
    ['threads', ThreadsRepository::class],
    ['participants', ParticipantsRepository::class],
]);
