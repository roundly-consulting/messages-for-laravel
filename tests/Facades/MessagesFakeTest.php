<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Testing\InteractsWithMessaging;
use RoundlyConsulting\Messages\Testing\MessagesFake;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', true);

    $this->alice = User::create();
    $this->bob = User::create();
    // Set up before faking, so it is not recorded.
    $this->crew = Messages::start('Crew')->withParticipants([$this->alice, $this->bob])->create();

    $this->fake = Messages::fake();
});

function failsAssertion(Closure $assertion): void
{
    expect($assertion)->toThrow(AssertionFailedError::class);
}

it('installs a manager subtype behind the facade and the container', function (): void {
    expect($this->fake)->toBeInstanceOf(MessagesFake::class)
        ->and(Messages::getFacadeRoot())->toBe($this->fake)
        ->and(app(MessagesManager::class))->toBe($this->fake);
});

/**
 * The bug: the old fake never called the parent constructor, so any manager method it did not
 * override read an uninitialised readonly property and crashed.
 */
it('runs the whole manager API, not only the methods it records', function (): void {
    Messages::send($this->crew, $this->alice, 'hi');

    expect(Messages::unreadCount($this->bob))->toBe(1)
        ->and(Messages::inboxFor($this->bob)->total())->toBe(1)
        ->and(Messages::threads($this->bob)->total())->toBe(1)
        ->and(Messages::thread($this->crew)->messages()->total())->toBe(1)
        ->and(Messages::prune(days: 30))->toBe(0);
});

it('still performs every operation it records', function (): void {
    $thread = Messages::start('Real')->withParticipant($this->alice)->create();
    Messages::to($thread)->from($this->alice)->send('persisted');

    expect(Thread::query()->whereKey($thread->getKey())->exists())->toBeTrue()
        ->and($thread->messages()->pluck('message')->all())->toBe(['persisted']);
});

it('lists recorded calls, optionally of one operation', function (): void {
    $message = Messages::send($this->crew, $this->alice, 'hi');
    Messages::markRead($this->crew, $this->bob);

    $sends = $this->fake->recorded(MessagingOperation::Send);

    expect($this->fake->recorded())->toHaveCount(2)
        ->and($sends)->toHaveCount(1)
        ->and($sends[0])->toBeInstanceOf(MessagingCall::class)
        ->and($sends[0]->text)->toBe('hi')
        ->and($sends[0]->participant->is($this->alice))->toBeTrue()
        ->and($sends[0]->result->is($message))->toBeTrue();
});

it('records nothing for an operation that throws', function (): void {
    expect(fn () => Messages::thread($this->crew)->rename('Nope', by: $this->bob))->toThrow(Exception::class);

    $this->fake->assertNothingRenamed();
});

it('asserts threads created by start() and by a first direct()', function (): void {
    $this->fake->assertNothingCreated();
    failsAssertion(fn () => $this->fake->assertThreadCreated());

    Messages::start('Launch')->withParticipant($this->alice)->create();

    $this->fake->assertThreadCreated();
    $this->fake->assertThreadCreated('Launch');
    failsAssertion(fn () => $this->fake->assertThreadCreated('Other'));
    failsAssertion(fn () => $this->fake->assertNothingCreated());
});

it('counts a direct() as created only when it had to create the thread', function (): void {
    $existing = Messages::direct($this->alice, $this->bob);
    $this->fake = Messages::fake();

    Messages::direct($this->alice, $this->bob);
    $this->fake->assertNothingCreated();

    $this->alice->conversationWith(User::create());
    $this->fake->assertThreadCreated();

    expect($existing->is_direct)->toBeTrue();
});

it('asserts sent messages from the builder, the flat send and the trait', function (): void {
    $this->fake->assertNothingSent();
    $this->fake->assertSentCount(0);
    failsAssertion(fn () => $this->fake->assertSent());

    Messages::to($this->crew)->from($this->alice)->send('builder');
    Messages::send($this->crew, $this->bob, 'flat');
    $this->alice->sendMessageTo($this->crew, 'trait');

    $this->fake->assertSent();
    $this->fake->assertSent('builder');
    $this->fake->assertSent('trait', to: $this->crew);
    $this->fake->assertSentCount(3);
    failsAssertion(fn () => $this->fake->assertSent('missing'));
    failsAssertion(fn () => $this->fake->assertSent('flat', to: Messages::start('Other')->create()));
    failsAssertion(fn () => $this->fake->assertSentCount(2));
    failsAssertion(fn () => $this->fake->assertNothingSent());
});

it('asserts renamed and archived threads', function (): void {
    $this->fake->assertNothingRenamed();
    $this->fake->assertNothingArchived();
    failsAssertion(fn () => $this->fake->assertThreadRenamed($this->crew));
    failsAssertion(fn () => $this->fake->assertThreadArchived($this->crew));

    Messages::thread($this->crew)->rename('Launch', by: $this->alice);
    Messages::thread($this->crew)->archive(by: $this->alice);

    $this->fake->assertThreadRenamed($this->crew);
    $this->fake->assertThreadRenamed($this->crew, to: 'Launch');
    $this->fake->assertThreadArchived($this->crew);
    failsAssertion(fn () => $this->fake->assertThreadRenamed($this->crew, to: 'Other'));
    failsAssertion(fn () => $this->fake->assertThreadArchived(Messages::start('Other')->create()));
    failsAssertion(fn () => $this->fake->assertNothingRenamed());
    failsAssertion(fn () => $this->fake->assertNothingArchived());
});

it('asserts reads from the facade, the handle and the models', function (): void {
    $this->fake->assertNothingMarkedRead();
    failsAssertion(fn () => $this->fake->assertMarkedRead($this->crew));

    $this->crew->markReadFor($this->bob);

    $this->fake->assertMarkedRead($this->crew);
    $this->fake->assertMarkedRead($this->crew, by: $this->bob);
    failsAssertion(fn () => $this->fake->assertMarkedRead($this->crew, by: $this->alice));
    failsAssertion(fn () => $this->fake->assertNothingMarkedRead());

    $this->crew->participants()->whereMorphedTo('participant', $this->alice)->firstOrFail()->markAsRead();
    $this->alice->markThreadRead($this->crew);

    expect($this->fake->recorded(MessagingOperation::MarkRead))->toHaveCount(3);
    $this->fake->assertMarkedRead($this->crew, by: $this->alice);
});

it('asserts typing signals, including through the thread model', function (): void {
    $this->fake->assertNothingTyping();
    failsAssertion(fn () => $this->fake->assertTyping($this->crew));

    $this->crew->typing($this->alice);

    $this->fake->assertTyping($this->crew);
    $this->fake->assertTyping($this->crew, $this->alice);
    failsAssertion(fn () => $this->fake->assertTyping($this->crew, $this->bob));
    failsAssertion(fn () => $this->fake->assertNothingTyping());
});

it('asserts added participants, including a self-join through the trait', function (): void {
    $carol = User::create();
    $this->fake->assertNothingAdded();
    failsAssertion(fn () => $this->fake->assertParticipantAdded($this->crew));

    $this->crew->forceFill(['everyone_can_join' => true])->save();
    $carol->joinThread($this->crew);

    $this->fake->assertParticipantAdded($this->crew);
    $this->fake->assertParticipantAdded($this->crew, $carol);
    failsAssertion(fn () => $this->fake->assertParticipantAdded($this->crew, $this->bob));
    failsAssertion(fn () => $this->fake->assertNothingAdded());
});

it('asserts removed participants, whether removed or leaving', function (): void {
    $this->fake->assertNothingRemoved();
    failsAssertion(fn () => $this->fake->assertParticipantRemoved($this->crew));

    Messages::thread($this->crew)->participants()->leave($this->bob);

    $this->fake->assertParticipantRemoved($this->crew, $this->bob);
    failsAssertion(fn () => $this->fake->assertParticipantRemoved($this->crew, $this->alice));
    failsAssertion(fn () => $this->fake->assertNothingRemoved());

    $carol = User::create();
    Messages::thread($this->crew)->participants()->add($carol, by: $this->alice);
    Messages::thread($this->crew)->participants()->remove($carol, by: $this->alice);

    $this->fake->assertParticipantRemoved($this->crew, $carol);
});

it('asserts role changes and ownership transfers', function (): void {
    $this->fake->assertNothingRoleChanged();
    $this->fake->assertNothingTransferred();
    failsAssertion(fn () => $this->fake->assertRoleChanged($this->crew));
    failsAssertion(fn () => $this->fake->assertOwnershipTransferred($this->crew));

    Messages::thread($this->crew)->participants()->setRole($this->bob, ParticipantRole::Admin, by: $this->alice);
    Messages::thread($this->crew)->participants()->transferOwnership(from: $this->alice, to: $this->bob);

    $this->fake->assertRoleChanged($this->crew);
    $this->fake->assertRoleChanged($this->crew, $this->bob, ParticipantRole::Admin);
    $this->fake->assertOwnershipTransferred($this->crew);
    $this->fake->assertOwnershipTransferred($this->crew, to: $this->bob);
    failsAssertion(fn () => $this->fake->assertRoleChanged($this->crew, $this->bob, ParticipantRole::Member));
    failsAssertion(fn () => $this->fake->assertOwnershipTransferred($this->crew, to: $this->alice));
    failsAssertion(fn () => $this->fake->assertNothingRoleChanged());
    failsAssertion(fn () => $this->fake->assertNothingTransferred());
});

it('asserts edited and deleted messages', function (): void {
    $message = Messages::send($this->crew, $this->alice, 'typo');
    $this->fake->assertNothingEdited();
    $this->fake->assertNothingDeleted();
    failsAssertion(fn () => $this->fake->assertEdited($message));
    failsAssertion(fn () => $this->fake->assertDeleted($message));

    Messages::message($message)->edit('fixed', by: $this->alice);
    Messages::thread($this->crew)->message($message)->delete(by: $this->alice);

    $this->fake->assertEdited($message);
    $this->fake->assertEdited($message, 'fixed');
    $this->fake->assertDeleted($message);
    failsAssertion(fn () => $this->fake->assertEdited($message, 'typo'));
    failsAssertion(fn () => $this->fake->assertDeleted(Messages::send($this->crew, $this->alice, 'other')));
    failsAssertion(fn () => $this->fake->assertNothingEdited());
    failsAssertion(fn () => $this->fake->assertNothingDeleted());
});

it('asserts prunes', function (): void {
    $this->fake->assertNothingPruned();
    failsAssertion(fn () => $this->fake->assertPruned());

    Messages::prune(days: 30);

    $this->fake->assertPruned();
    $this->fake->assertPruned(30);
    failsAssertion(fn () => $this->fake->assertPruned(7));
    failsAssertion(fn () => $this->fake->assertNothingPruned());
});

it('records calls from the InteractsWithMessaging helper', function (): void {
    $helper = new class
    {
        use InteractsWithMessaging;
    };

    $thread = $helper->startConversation($this->alice, $this->bob);
    $helper->actingAsParticipant($this->alice)->sendMessageAs($thread, 'from the helper');

    $this->fake->assertThreadCreated();
    $this->fake->assertSent('from the helper', to: $thread);
});
