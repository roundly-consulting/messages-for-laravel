<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\MessageDeleted;
use RoundlyConsulting\Messages\Events\MessageEdited;
use RoundlyConsulting\Messages\Events\ParticipantJoined;
use RoundlyConsulting\Messages\Events\ParticipantLeft;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Events\ThreadArchived;
use RoundlyConsulting\Messages\Events\ThreadRead;
use RoundlyConsulting\Messages\Events\ThreadRenamed;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Handles\MessageHandle;
use RoundlyConsulting\Messages\Handles\ParticipantsHandle;
use RoundlyConsulting\Messages\Handles\ThreadHandle;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', true);

    $this->alice = User::create();
    $this->bob = User::create();
    $this->crew = Messages::start('Crew')->withParticipants([$this->alice, $this->bob])->create();
});

it('starts a thread through the fluent builder', function (): void {
    expect(Messages::start('x'))->toBeInstanceOf(PendingThread::class);

    $thread = Messages::start('Project X')
        ->public()
        ->everyoneCanJoin()
        ->withParticipants([$this->alice, $this->bob])
        ->create();

    expect($thread)->toBeInstanceOf(Thread::class)
        ->and($thread->name)->toBe('Project X')
        ->and($thread->is_public)->toBeTrue()
        ->and($thread->everyone_can_join)->toBeTrue()
        ->and($thread->roleOf($this->alice))->toBe(ParticipantRole::Owner)
        ->and($thread->roleOf($this->bob))->toBe(ParticipantRole::Member);
});

it('sends through the builder and the flat send()', function (): void {
    expect(Messages::to($this->crew))->toBeInstanceOf(PendingMessage::class);

    $built = Messages::to($this->crew)->from($this->alice)->send('Hello');
    $flat = Messages::send($this->crew, $this->bob, 'Hi');
    $anonymous = Messages::send($this->crew, null, 'Heads up');

    expect($built->message)->toBe('Hello')
        ->and($built->sender_id)->toBe($this->alice->getKey())
        ->and($flat->sender_id)->toBe($this->bob->getKey())
        ->and($anonymous->sender_id)->toBeNull()
        ->and($this->crew->messages()->count())->toBe(3);
});

it('finds or creates one direct thread per pair', function (): void {
    $first = Messages::direct($this->alice, $this->bob);
    $second = Messages::direct($this->bob, $this->alice);

    expect($first->is_direct)->toBeTrue()
        ->and($second->getKey())->toBe($first->getKey());
});

it('counts unread, marks read and lists the inbox', function (): void {
    Messages::send($this->crew, $this->alice, 'one');

    expect(Messages::unreadCount($this->bob))->toBe(1)
        ->and(Messages::unreadCount($this->bob, $this->crew))->toBe(1);

    $participant = Messages::markRead($this->crew, $this->bob);

    expect($participant)->toBeInstanceOf(Participant::class)
        ->and($participant->read_at)->not->toBeNull()
        ->and(Messages::unreadCount($this->bob))->toBe(0)
        ->and(Messages::inboxFor($this->bob)->total())->toBe(1);
});

it('lists public threads, plus the model\'s own threads when one is given', function (): void {
    Messages::start('Town hall')->public()->create();
    Messages::start('Elsewhere')->private()->create();

    $public = Messages::threads();
    $forBob = Messages::threads(for: $this->bob, perPage: 1, page: 2);

    expect($public)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($public->getCollection()->pluck('name')->all())->toBe(['Town hall'])
        ->and($forBob->total())->toBe(2)
        ->and($forBob->perPage())->toBe(1)
        ->and($forBob->getCollection())->toHaveCount(1)
        ->and(Messages::threads($this->bob)->getCollection()->pluck('name')->sort()->values()->all())
        ->toBe(['Crew', 'Town hall']);
});

it('returns a thread handle scoped to the thread', function (): void {
    $handle = Messages::thread($this->crew);

    expect($handle)->toBeInstanceOf(ThreadHandle::class)
        ->and($handle->model())->toBe($this->crew)
        ->and($handle->participants())->toBeInstanceOf(ParticipantsHandle::class);
});

it('renames and archives a thread as a manager', function (): void {
    Event::fake([ThreadRenamed::class, ThreadArchived::class]);

    Messages::thread($this->crew)->rename('Launch crew', by: $this->alice);
    Messages::thread($this->crew)->archive(by: $this->alice);

    expect($this->crew->fresh()->name)->toBe('Launch crew')
        ->and($this->crew->fresh()->isArchived())->toBeTrue();

    Event::assertDispatched(ThreadRenamed::class);
    Event::assertDispatched(ThreadArchived::class);
});

it('refuses a rename or archive by a plain member', function (string $operation): void {
    match ($operation) {
        'rename' => Messages::thread($this->crew)->rename('Mine now', by: $this->bob),
        'archive' => Messages::thread($this->crew)->archive(by: $this->bob),
    };
})->with(['rename', 'archive'])->throws(UnauthorizedMessagingAction::class);

it('marks a thread read through the handle', function (): void {
    Event::fake([ThreadRead::class]);
    Messages::send($this->crew, $this->alice, 'hi');

    Messages::thread($this->crew)->markRead($this->bob);

    expect($this->crew->unreadCountFor($this->bob))->toBe(0);
    Event::assertDispatched(ThreadRead::class);
});

it('signals typing only while broadcasting is on', function (): void {
    Event::fake([ParticipantTyping::class]);

    Messages::thread($this->crew)->typing($this->alice);
    Event::assertNotDispatched(ParticipantTyping::class);

    config()->set('messages.broadcasting.enabled', true);
    Messages::thread($this->crew)->typing($this->alice);

    Event::assertDispatched(
        ParticipantTyping::class,
        fn (ParticipantTyping $event): bool => $event->thread->is($this->crew) && $event->participant->is($this->alice),
    );
});

it('paginates a thread\'s messages newest first', function (): void {
    Messages::send($this->crew, $this->alice, 'first');
    $latest = Messages::send($this->crew, $this->bob, 'second');

    $page = Messages::thread($this->crew)->messages(perPage: 1);

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(2)
        ->and($page->getCollection()->first()->getKey())->toBe($latest->getKey())
        ->and($page->getCollection()->first()->relationLoaded('sender'))->toBeTrue()
        ->and(Messages::thread($this->crew)->messages(perPage: 1, page: 2)->getCollection()->first()->message)
        ->toBe('first');
});

it('adds, re-roles and removes participants', function (): void {
    Event::fake([ParticipantJoined::class, ParticipantLeft::class]);
    $carol = User::create();
    $participants = Messages::thread($this->crew)->participants();

    $added = $participants->add($carol, ParticipantRole::Admin, by: $this->alice);

    expect($added->role)->toBe(ParticipantRole::Admin);
    Event::assertDispatched(ParticipantJoined::class);

    $demoted = $participants->setRole($carol, ParticipantRole::Member, by: $this->alice);

    expect($demoted->role)->toBe(ParticipantRole::Member)
        ->and($this->crew->roleOf($carol))->toBe(ParticipantRole::Member);

    $participants->remove($carol, by: $this->alice);

    expect($this->crew->roleOf($carol))->toBeNull();
    Event::assertDispatched(ParticipantLeft::class);
});

it('refuses participant management by a plain member', function (): void {
    Messages::thread($this->crew)->participants()->add(User::create(), by: $this->bob);
})->throws(UnauthorizedMessagingAction::class);

it('lets a member leave without manage rights', function (): void {
    Messages::thread($this->crew)->participants()->leave($this->bob);

    expect($this->crew->participants()->whereMorphedTo('participant', $this->bob)->exists())->toBeFalse();
});

it('transfers ownership and demotes the previous owner', function (): void {
    $next = Messages::thread($this->crew)->participants()->transferOwnership(from: $this->alice, to: $this->bob);

    expect($next->role)->toBe(ParticipantRole::Owner)
        ->and($this->crew->roleOf($this->alice))->toBe(ParticipantRole::Admin);
});

it('accepts a participant row of the same thread in place of the model', function (): void {
    $row = $this->crew->participants()->whereMorphedTo('participant', $this->bob)->firstOrFail();

    Messages::thread($this->crew)->participants()->setRole($row, ParticipantRole::Admin, by: $this->alice);

    expect($this->crew->roleOf($this->bob))->toBe(ParticipantRole::Admin);
});

it('edits and deletes a message through the message handle', function (): void {
    Event::fake([MessageEdited::class, MessageDeleted::class]);
    $message = Messages::send($this->crew, $this->alice, 'typo');

    $handle = Messages::message($message);

    expect($handle)->toBeInstanceOf(MessageHandle::class)
        ->and($handle->model())->toBe($message)
        ->and($handle->edit('fixed', by: $this->alice)->message)->toBe('fixed');

    $handle->delete(by: $this->alice);

    expect(Message::query()->withTrashed()->find($message->getKey())->trashed())->toBeTrue();
    Event::assertDispatched(MessageEdited::class);
    Event::assertDispatched(MessageDeleted::class);
});

it('lets a manager delete another participant\'s message', function (): void {
    $message = Messages::send($this->crew, $this->bob, 'off-topic');

    Messages::thread($this->crew)->message($message)->delete(by: $this->alice);

    expect($message->fresh()->trashed())->toBeTrue();
});

/**
 * The bug: EditMessage took no actor, so `app(EditMessage::class)` — the only way to edit —
 * let anyone reword anyone's message. Not even an owner may put words in a member's mouth.
 */
it('refuses to edit a message on behalf of anyone but its author', function (User $editor): void {
    $message = Messages::send($this->crew, $this->bob, 'my words');

    expect(fn () => Messages::message($message)->edit('your words', by: $editor))
        ->toThrow(UnauthorizedMessagingAction::class);

    expect($message->fresh()->message)->toBe('my words');
})->with([
    'the owner' => fn (): User => $this->alice,
    'a stranger' => fn (): User => User::create(),
]);

it('prunes old messages, optionally in one thread', function (): void {
    $this->travelTo(now()->subDays(40));
    $old = Messages::send($this->crew, $this->alice, 'old');
    $other = Messages::start('Other')->withParticipant($this->alice)->create();
    Messages::send($other, $this->alice, 'old elsewhere');
    $this->travelBack();

    expect(Messages::prune(days: 30, thread: $this->crew))->toBe(1)
        ->and(Message::query()->withTrashed()->find($old->getKey()))->toBeNull()
        ->and($other->messages()->count())->toBe(1)
        ->and(Messages::prune(days: 30))->toBe(1);
});

it('prunes with the configured retention by default', function (): void {
    config()->set('messages.prune.days', 10);
    $this->travelTo(now()->subDays(11));
    Messages::send($this->crew, $this->alice, 'stale');
    $this->travelBack();
    Messages::send($this->crew, $this->alice, 'fresh');

    expect(Messages::prune())->toBe(1)
        ->and($this->crew->messages()->pluck('message')->all())->toBe(['fresh']);
});

/**
 * A window below one day puts the cutoff at or after now: `prune(0)` deleted everything older
 * than this second, `prune(-1)` everything sent so far, attachment files included. The config
 * path already refused such a window; the explicit argument bypassed it.
 */
it('refuses a prune window below one day before deleting anything', function (int $days): void {
    $this->travelTo(now()->subHour());
    Messages::send($this->crew, $this->alice, 'an hour old');
    $this->travelBack();
    Messages::send($this->crew, $this->alice, 'just now');

    expect(fn () => Messages::prune(days: $days))
        ->toThrow(InvalidArgumentException::class, 'at least 1 day');

    expect(Message::query()->withTrashed()->count())->toBe(2)
        ->and(Messages::prune(days: 1))->toBe(0);
})->with([0, -1]);

it('resolves the manager by dependency injection and runs the same code', function (): void {
    $manager = app(MessagesManager::class);

    expect($manager)->toBe(app(MessagesManager::class))
        ->and(Messages::getFacadeRoot())->toBe($manager);

    $thread = $manager->start('Injected')->withParticipant($this->alice)->create();
    $manager->thread($thread)->rename('Renamed', by: $this->alice);

    expect($thread->fresh()->name)->toBe('Renamed');
});

describe('cross-thread refusals', function (): void {
    beforeEach(function (): void {
        $this->other = Messages::start('Other')->withParticipants([$this->alice, $this->bob])->create();
    });

    it('refuses a message of another thread', function (): void {
        $foreign = Messages::send($this->other, $this->bob, 'elsewhere');

        Messages::thread($this->crew)->message($foreign);
    })->throws(MessageException::class, 'belongs to another thread');

    it('refuses a participant row of another thread', function (string $operation): void {
        $foreign = $this->other->participants()->whereMorphedTo('participant', $this->bob)->firstOrFail();
        $participants = Messages::thread($this->crew)->participants();

        match ($operation) {
            'add' => $participants->add($foreign, by: $this->alice),
            'remove' => $participants->remove($foreign, by: $this->alice),
            'leave' => $participants->leave($foreign),
            'setRole' => $participants->setRole($foreign, ParticipantRole::Admin, by: $this->alice),
            'transferOwnership' => $participants->transferOwnership(from: $this->alice, to: $foreign),
            'markRead' => Messages::thread($this->crew)->markRead($foreign),
            'typing' => Messages::thread($this->crew)->typing($foreign),
        };
    })->with(['add', 'remove', 'leave', 'setRole', 'transferOwnership', 'markRead', 'typing'])
        ->throws(ParticipationException::class, 'belongs to another thread');

    it('refuses a model that is not a participant of the thread', function (): void {
        $outsider = User::create();
        Messages::thread($this->other)->participants()->add($outsider);

        Messages::thread($this->crew)->participants()->setRole($outsider, ParticipantRole::Admin, by: $this->alice);
    })->throws(ParticipationException::class);
});

it('refuses a participant row whose model no longer exists', function (): void {
    $row = $this->crew->participants()->whereMorphedTo('participant', $this->bob)->firstOrFail();
    $this->bob->delete();

    Messages::thread($this->crew)->participants()->remove($row->fresh(), by: $this->alice);
})->throws(ParticipationException::class, 'no longer exists');
