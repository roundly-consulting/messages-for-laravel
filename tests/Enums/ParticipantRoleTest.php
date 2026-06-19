<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\ParticipantRole;

it('reports which roles can manage', function () {
    expect(ParticipantRole::Owner->canManage())->toBeTrue()
        ->and(ParticipantRole::Admin->canManage())->toBeTrue()
        ->and(ParticipantRole::Member->canManage())->toBeFalse();
});

it('reports owner status', function () {
    expect(ParticipantRole::Owner->isOwner())->toBeTrue()
        ->and(ParticipantRole::Admin->isOwner())->toBeFalse()
        ->and(ParticipantRole::Member->isOwner())->toBeFalse();
});

it('ranks roles for outranking', function () {
    expect(ParticipantRole::Owner->outranks(ParticipantRole::Admin))->toBeTrue()
        ->and(ParticipantRole::Admin->outranks(ParticipantRole::Member))->toBeTrue()
        ->and(ParticipantRole::Member->outranks(ParticipantRole::Owner))->toBeFalse()
        ->and(ParticipantRole::Admin->outranks(ParticipantRole::Admin))->toBeFalse();
});
