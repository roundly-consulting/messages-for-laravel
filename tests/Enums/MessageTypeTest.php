<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\MessageType;

it('exposes text and system cases', function () {
    expect(MessageType::Text->value)->toBe('text')
        ->and(MessageType::System->value)->toBe('system')
        ->and(MessageType::cases())->toHaveCount(2);
});

it('resolves from its backing value', function () {
    expect(MessageType::from('text'))->toBe(MessageType::Text)
        ->and(MessageType::from('system'))->toBe(MessageType::System);
});
