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

it('exposes enum helpers from the trait', function () {
    expect(MessageType::labels()->all())->toBe(['Text', 'System'])
        ->and(MessageType::values()->all())->toBe(['text', 'system'])
        ->and(MessageType::toOptions()->all())->toBe(['text' => 'Text', 'system' => 'System'])
        ->and(MessageType::validationRule())->toBe('in:text,system')
        ->and(MessageType::Text->label())->toBe('Text');
});

it('exposes typed options dtos from the trait', function () {
    $first = MessageType::options()->first();

    expect($first->value)->toBe('text')
        ->and($first->label)->toBe('Text')
        ->and($first->name)->toBe('Text');
});
