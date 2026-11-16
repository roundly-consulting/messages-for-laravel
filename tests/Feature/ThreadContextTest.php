<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Exceptions\ThreadContextException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\Company;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;

/*
 * The optional polymorphic thread context (2.2.0) without the fail-closed hook: a thread may
 * belong to a company, a project, an order. The same participants hold separate threads per
 * context, every listing helper narrows to a context when given one, and a thread without a
 * context behaves exactly as before.
 */

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', false);

    $this->alice = User::create();
    $this->bob = User::create();
    $this->acme = Company::create();
    $this->globex = Company::create();
});

it('adds nullable, indexed context columns to the threads table', function (): void {
    expect(Schema::hasColumns('messaging_threads', ['context_type', 'context_id']))->toBeTrue()
        ->and(Schema::hasIndex('messaging_threads', ['context_type', 'context_id']))->toBeTrue();

    $thread = Messages::start('Plain')->withParticipant($this->alice)->create()->fresh();

    expect($thread->context_type)->toBeNull()
        ->and($thread->context_id)->toBeNull()
        ->and($thread->context)->toBeNull()
        ->and($thread->isInContext(null))->toBeTrue()
        ->and($thread->isInContext($this->acme))->toBeFalse();
});

it('starts a thread in a context through the builder', function (): void {
    $thread = Messages::start('Deal')->for($this->acme)->withParticipants([$this->alice, $this->bob])->create();
    $fresh = $thread->fresh();

    expect($fresh->context_type)->toBe($this->acme->getMorphClass())
        ->and((string) $fresh->context_id)->toBe((string) $this->acme->getKey())
        ->and($fresh->context)->toBeInstanceOf(Company::class)
        ->and($fresh->context->is($this->acme))->toBeTrue()
        ->and($fresh->isInContext($this->acme))->toBeTrue()
        ->and($fresh->isInContext($this->globex))->toBeFalse()
        ->and($fresh->isInContext(null))->toBeFalse();
});

it('refuses an unsaved context everywhere a context is taken', function (): void {
    $unsaved = new Company;

    expect(fn () => Messages::start('Deal')->for($unsaved)->create())
        ->toThrow(ThreadContextException::class, 'A thread context must be a saved model.')
        ->and(fn () => Messages::direct($this->alice, $this->bob, $unsaved))
        ->toThrow(ThreadContextException::class)
        ->and(fn () => Thread::query()->forContext($unsaved)->get())
        ->toThrow(ThreadContextException::class)
        ->and(fn () => Thread::directKeyFor($this->alice, $this->bob, $unsaved))
        ->toThrow(ThreadContextException::class)
        ->and(Thread::query()->count())->toBe(0);
});

it('narrows thread queries with forContext() and withoutContext()', function (): void {
    $acme = Messages::start('Acme')->for($this->acme)->withParticipant($this->alice)->create();
    $globex = Messages::start('Globex')->for($this->globex)->withParticipant($this->alice)->create();
    $restaurant = Restaurant::create(['id' => $this->acme->getKey()]);
    $sameKeyOtherType = Messages::start('Diner')->for($restaurant)->withParticipant($this->alice)->create();
    $plain = Messages::start('Plain')->withParticipant($this->alice)->create();

    expect(Thread::query()->forContext($this->acme)->pluck('id')->all())->toBe([$acme->getKey()])
        ->and(Thread::query()->forContext($this->globex)->pluck('id')->all())->toBe([$globex->getKey()])
        // Same key, another morph type: another context.
        ->and(Thread::query()->forContext($restaurant)->pluck('id')->all())->toBe([$sameKeyOtherType->getKey()])
        ->and(Thread::query()->withoutContext()->pluck('id')->all())->toBe([$plain->getKey()])
        ->and(Thread::query()->count())->toBe(4);
});

it('lists only the given context\'s threads for the same participants', function (): void {
    $acme = Messages::start('Acme')->for($this->acme)->withParticipants([$this->alice, $this->bob])->create();
    $globex = Messages::start('Globex')->for($this->globex)->withParticipants([$this->alice, $this->bob])->create();
    $plain = Messages::start('Plain')->withParticipants([$this->alice, $this->bob])->create();

    Messages::send($acme, $this->alice, 'acme one');
    Messages::send($acme, $this->alice, 'acme two');
    Messages::send($globex, $this->alice, 'globex');
    Messages::send($plain, $this->alice, 'plain');

    $ids = static fn (iterable $threads): array => collect($threads)->map->getKey()->sort()->values()->all();

    expect($ids(Messages::inboxFor($this->bob, context: $this->acme)->items()))->toBe([$acme->getKey()])
        ->and($ids(Messages::inboxFor($this->bob, context: $this->globex)->items()))->toBe([$globex->getKey()])
        ->and($ids(Messages::threads($this->bob, context: $this->acme)->items()))->toBe([$acme->getKey()])
        ->and($ids(Messages::threads($this->bob, context: $this->globex)->items()))->toBe([$globex->getKey()])
        ->and($ids($this->bob->threads($this->acme)))->toBe([$acme->getKey()])
        ->and($ids($this->bob->conversations($this->globex)))->toBe([$globex->getKey()])
        ->and($ids($this->bob->unreadThreads($this->acme)))->toBe([$acme->getKey()])
        ->and(Messages::unreadCount($this->bob, context: $this->acme))->toBe(2)
        ->and(Messages::unreadCount($this->bob, context: $this->globex))->toBe(1)
        ->and($this->bob->unreadCount(context: $this->acme))->toBe(2)
        ->and($this->bob->unreadCount(context: $this->globex))->toBe(1);
});

it('leaves every listing unchanged when no context is given', function (): void {
    $acme = Messages::start('Acme')->for($this->acme)->withParticipants([$this->alice, $this->bob])->create();
    $plain = Messages::start('Plain')->withParticipants([$this->alice, $this->bob])->create();

    Messages::send($acme, $this->alice, 'acme');
    Messages::send($plain, $this->alice, 'plain');

    $ids = static fn (iterable $threads): array => collect($threads)->map->getKey()->sort()->values()->all();
    $both = collect([$acme->getKey(), $plain->getKey()])->sort()->values()->all();

    expect($ids(Messages::inboxFor($this->bob)->items()))->toBe($both)
        ->and($ids(Messages::threads($this->bob)->items()))->toBe($both)
        ->and($ids($this->bob->threads()))->toBe($both)
        ->and($ids($this->bob->unreadThreads()))->toBe($both)
        ->and(Messages::unreadCount($this->bob))->toBe(2)
        ->and($this->bob->unreadCount())->toBe(2);
});

it('keeps one direct thread per pair per context — never reusing one across contexts', function (): void {
    $acme = Messages::direct($this->alice, $this->bob, $this->acme);
    $globex = Messages::direct($this->alice, $this->bob, $this->globex);
    $plain = Messages::direct($this->alice, $this->bob);

    expect(collect([$acme, $globex, $plain])->map->getKey()->unique())->toHaveCount(3)
        ->and($acme->isInContext($this->acme))->toBeTrue()
        ->and($globex->isInContext($this->globex))->toBeTrue()
        ->and($plain->isInContext(null))->toBeTrue()
        // Found again — whichever side comes first.
        ->and(Messages::direct($this->bob, $this->alice, $this->acme)->getKey())->toBe($acme->getKey())
        ->and(Messages::direct($this->alice, $this->bob, $this->globex)->getKey())->toBe($globex->getKey())
        ->and(Messages::direct($this->bob, $this->alice)->getKey())->toBe($plain->getKey())
        ->and(Thread::query()->direct()->count())->toBe(3);
});

it('finds a context DM created first, then keeps the context-less one separate', function (): void {
    // The order matters for the dedupe: a context-less lookup must not return the context DM.
    $acme = Messages::direct($this->alice, $this->bob, $this->acme);
    $plain = Messages::direct($this->alice, $this->bob);

    expect($plain->getKey())->not->toBe($acme->getKey())
        ->and($plain->isInContext(null))->toBeTrue();
});

it('carries the context in the direct key, symmetric and distinct per context', function (): void {
    $plain = Thread::directKeyFor($this->alice, $this->bob);
    $acme = Thread::directKeyFor($this->alice, $this->bob, $this->acme);

    expect(Thread::directKeyFor($this->bob, $this->alice, $this->acme))->toBe($acme)
        ->and($acme)->not->toBe($plain)
        ->and($acme)->not->toBe(Thread::directKeyFor($this->alice, $this->bob, $this->globex))
        ->and($acme)->toStartWith('c:')
        ->and(strlen($acme))->toBeLessThanOrEqual(600)
        ->and(Messages::direct($this->alice, $this->bob, $this->acme)->direct_key)->toBe($acme);
});

it('keys a builder-started direct thread by its context', function (): void {
    $acme = Messages::start()->direct()->for($this->acme)->withParticipants([$this->alice, $this->bob])->create();

    expect($acme->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->bob, $this->acme))
        ->and(Messages::direct($this->bob, $this->alice, $this->acme)->getKey())->toBe($acme->getKey());

    // Another context is another DM; a second one in the same context is refused by the index.
    $globex = Messages::start()->direct()->for($this->globex)->withParticipants([$this->alice, $this->bob])->create();

    expect($globex->getKey())->not->toBe($acme->getKey())
        ->and(fn () => Messages::start()->direct()->for($this->acme)->withParticipants([$this->alice, $this->bob])->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('hands a deleted context DM\'s key to a fresh one in the same context only', function (): void {
    $acme = Messages::direct($this->alice, $this->bob, $this->acme);
    $globex = Messages::direct($this->alice, $this->bob, $this->globex);
    $acme->delete();

    $fresh = Messages::direct($this->alice, $this->bob, $this->acme);

    expect($fresh->getKey())->not->toBe($acme->getKey())
        ->and($fresh->isInContext($this->acme))->toBeTrue()
        ->and($fresh->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->bob, $this->acme))
        ->and(Thread::withTrashed()->find($acme->getKey())?->direct_key)->toBeNull()
        // The other context's DM keeps its key.
        ->and($globex->fresh()?->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->bob, $this->globex));
});

it('starts and finds conversations in a context through the model trait', function (): void {
    $group = $this->alice->startConversationWith($this->bob, 'Deal', context: $this->acme);
    $direct = $this->alice->conversationWith($this->bob, context: $this->acme);

    expect($group->isInContext($this->acme))->toBeTrue()
        ->and($group->name)->toBe('Deal')
        ->and($direct->isInContext($this->acme))->toBeTrue()
        ->and($direct->is_direct)->toBeTrue()
        ->and($this->bob->conversationWith($this->alice, $this->acme)->getKey())->toBe($direct->getKey())
        ->and($this->bob->conversationWith($this->alice)->getKey())->not->toBe($direct->getKey())
        ->and($this->alice->startConversationWith([$this->bob])->isInContext(null))->toBeTrue();
});

it('renders the context relation from the factory\'s for()', function (): void {
    $thread = Thread::factory()->for($this->acme, 'context')->create();

    expect($thread->fresh()?->isInContext($this->acme))->toBeTrue();
});

it('asserts a thread created in a context through the fake', function (): void {
    $fake = Messages::fake();

    Messages::start('Deal')->for($this->acme)->withParticipant($this->alice)->create();
    $this->alice->conversationWith($this->bob, context: $this->globex);

    Messages::assertThreadCreated('Deal', $this->acme);
    Messages::assertThreadCreated(context: $this->globex);
    Messages::assertThreadCreated();

    $direct = $fake->recorded(MessagingOperation::Direct);

    expect($direct)->toHaveCount(1)
        ->and($direct[0])->toBeInstanceOf(MessagingCall::class)
        ->and($direct[0]->context?->is($this->globex))->toBeTrue()
        ->and($fake->recorded(MessagingOperation::Start)[0]->context?->is($this->acme))->toBeTrue()
        ->and(fn () => Messages::assertThreadCreated('Deal', $this->globex))
        ->toThrow(AssertionFailedError::class, 'Expected a thread named [Deal] to be created in context [Company:'.$this->globex->getKey().'], but none was.')
        ->and(fn () => Messages::assertThreadCreated(context: Company::create()))
        ->toThrow(AssertionFailedError::class);
});

it('refuses through the fake and records nothing when the context is unsaved', function (): void {
    Messages::fake();

    expect(fn () => Messages::direct($this->alice, $this->bob, new Company))->toThrow(ThreadContextException::class)
        ->and(fn () => Messages::start('Deal')->for(new Company)->create())->toThrow(ThreadContextException::class);

    Messages::assertNothingCreated();
});
