<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * A refusal reads entirely in the current locale: the `:action` (and `:role`) it names come
 * from the language files, never from English phrases in code.
 */
beforeEach(function (): void {
    config()->set('messages.permissions.enabled', true);

    $this->owner = User::create();
    $this->admin = User::create();
    $this->member = User::create();
    $this->other = User::create();
    $this->stranger = User::create();

    $this->thread = Messages::start('Crew')
        ->withParticipants([$this->owner, $this->admin, $this->member, $this->other])
        ->create();
    Messages::thread($this->thread)->participants()->setRole($this->admin, ParticipantRole::Admin, by: $this->owner);

    $this->message = Messages::send($this->thread, $this->owner, 'hi');
});

afterEach(function (): void {
    app()->setLocale('en');
});

it('names the refused action in the current locale', function (string $operation, string $locale, string $expected): void {
    $participants = Messages::thread($this->thread)->participants();

    [$actor, $attempt] = match ($operation) {
        'join' => [$this->stranger, fn () => $participants->add($this->stranger, by: $this->stranger)],
        'send' => [$this->stranger, fn () => Messages::send($this->thread, $this->stranger, 'let me in')],
        'add' => [$this->member, fn () => $participants->add(User::create(), by: $this->member)],
        'remove' => [$this->member, fn () => $participants->remove($this->other, by: $this->member)],
        'remove the owner' => [$this->admin, fn () => $participants->remove($this->owner, by: $this->admin)],
        'set role' => [$this->admin, fn () => $participants->setRole($this->member, ParticipantRole::Admin, by: $this->admin)],
        'transfer' => [$this->admin, fn () => $participants->transferOwnership(from: $this->admin, to: $this->member)],
        'rename' => [$this->member, fn () => Messages::thread($this->thread)->rename('Taken', by: $this->member)],
        'archive' => [$this->member, fn () => Messages::thread($this->thread)->archive(by: $this->member)],
        'delete' => [$this->member, fn () => Messages::message($this->message)->delete(by: $this->member)],
        'edit' => [$this->member, fn () => Messages::message($this->message)->edit('mine now', by: $this->member)],
    };

    app()->setLocale($locale);

    /** @var Model $actor */
    $id = '['.$actor->getMorphClass().':'.$actor->getKey().']';

    expect($attempt)->toThrow(UnauthorizedMessagingAction::class, str_replace('[X]', $id, $expected));
})->with([
    ['join', 'en', '[X] is not authorized to join this thread.'],
    ['join', 'sk', '[X] nemá oprávnenie pripojiť sa k tejto konverzácii.'],
    ['send', 'en', '[X] is not authorized to send messages to this thread.'],
    ['send', 'sk', '[X] nemá oprávnenie posielať správy do tejto konverzácie.'],
    ['add', 'en', '[X] requires the admin role to add participants.'],
    ['add', 'sk', '[X] potrebuje rolu „správca“, ak chce pridávať účastníkov.'],
    ['remove', 'en', '[X] requires the admin role to remove participants.'],
    ['remove', 'sk', '[X] potrebuje rolu „správca“, ak chce odoberať účastníkov.'],
    ['remove the owner', 'en', '[X] is not authorized to remove this participant.'],
    ['remove the owner', 'sk', '[X] nemá oprávnenie odobrať tohto účastníka.'],
    ['set role', 'en', '[X] requires the owner role to change participant roles.'],
    ['set role', 'sk', '[X] potrebuje rolu „vlastník“, ak chce meniť roly účastníkov.'],
    ['transfer', 'en', '[X] requires the owner role to transfer ownership.'],
    ['transfer', 'sk', '[X] potrebuje rolu „vlastník“, ak chce odovzdať vlastníctvo.'],
    ['rename', 'en', '[X] requires the admin role to rename the thread.'],
    ['rename', 'sk', '[X] potrebuje rolu „správca“, ak chce premenovať konverzáciu.'],
    ['archive', 'en', '[X] requires the admin role to archive the thread.'],
    ['archive', 'sk', '[X] potrebuje rolu „správca“, ak chce archivovať konverzáciu.'],
    ['delete', 'en', '[X] is not authorized to delete this message.'],
    ['delete', 'sk', '[X] nemá oprávnenie odstrániť túto správu.'],
    ['edit', 'en', '[X] is not authorized to edit this message.'],
    ['edit', 'sk', '[X] nemá oprávnenie upraviť túto správu.'],
]);

it('refuses a delete on a deleted thread in the current locale', function (): void {
    $this->thread->delete();
    app()->setLocale('sk');

    $id = '['.$this->owner->getMorphClass().':'.$this->owner->getKey().']';

    expect(fn () => Messages::message($this->message->fresh())->delete(by: $this->owner))
        ->toThrow(UnauthorizedMessagingAction::class, $id.' nemá oprávnenie odstrániť túto správu.');
});

it('ships a role name for every participant role in every language', function (string $locale): void {
    app()->setLocale($locale);

    foreach (ParticipantRole::cases() as $role) {
        $key = 'messages::messages.permissions.roles.'.$role->value;

        expect(trans($key))->toBeString()->not->toBe($key);
    }
})->with(['en', 'sk']);

it('keeps a caller-supplied action phrase as it is', function (): void {
    $actor = User::create();
    $id = '['.$actor->getMorphClass().':'.$actor->getKey().']';

    expect(UnauthorizedMessagingAction::for($actor, 'feed the cat')->getMessage())
        ->toBe($id.' is not authorized to feed the cat.')
        ->and(UnauthorizedMessagingAction::requiresRole($actor, ParticipantRole::Admin, 'feed the cat')->getMessage())
        ->toBe($id.' requires the admin role to feed the cat.');
});
