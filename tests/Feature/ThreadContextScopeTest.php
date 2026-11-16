<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\ThreadContextException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Scopes\ThreadContextScope;
use RoundlyConsulting\Messages\Testing\MessagesFake;
use RoundlyConsulting\Messages\Tests\Fixtures\AuthorisingBroadcaster;
use RoundlyConsulting\Messages\Tests\Fixtures\CurrentCompanyContext;
use RoundlyConsulting\Messages\Tests\Models\Company;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * The fail-closed context hook (2.2.0). While a resolver returns a context, every thread query
 * sees only that context's threads — another context's thread (or one without a context) is
 * never found — and the API refuses one it is handed. Each path is covered allowed + refused.
 */

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', false);

    $this->alice = User::create();
    $this->bob = User::create();
    $this->acme = Company::create();
    $this->globex = Company::create();

    // Seeded with the hook off, so both contexts (and none) hold a thread.
    $this->own = Messages::start('Acme deal')->for($this->acme)->withParticipants([$this->alice, $this->bob])->create();
    $this->foreign = Messages::start('Globex deal')->for($this->globex)->withParticipants([$this->alice, $this->bob])->create();
    $this->plain = Messages::start('Plain')->withParticipants([$this->alice, $this->bob])->create();

    $this->ownMessage = Messages::send($this->own, $this->alice, 'acme');
    $this->foreignMessage = Messages::send($this->foreign, $this->alice, 'globex');
    Messages::send($this->plain, $this->alice, 'plain');

    $this->actAs = function (?Company $company): void {
        Messages::resolveContextUsing(static fn (): ?Company => $company);
    };
});

afterEach(function (): void {
    CurrentCompanyContext::$current = null;
});

it('is off until a resolver is set', function (): void {
    expect(Messages::currentContext())->toBeNull()
        ->and(Thread::query()->count())->toBe(3);
});

it('limits every thread query to the current context', function (): void {
    ($this->actAs)($this->acme);

    expect(Messages::currentContext()?->is($this->acme))->toBeTrue()
        ->and(Thread::query()->pluck('id')->all())->toBe([$this->own->getKey()])
        ->and(Thread::find($this->own->getKey()))->not->toBeNull()
        ->and(Thread::find($this->foreign->getKey()))->toBeNull()
        // A thread without a context is outside every context too.
        ->and(Thread::find($this->plain->getKey()))->toBeNull()
        ->and(Thread::withTrashed()->find($this->foreign->getKey()))->toBeNull()
        ->and(fn () => Thread::findOrFail($this->foreign->getKey()))->toThrow(ModelNotFoundException::class)
        ->and((new Thread)->resolveRouteBinding($this->own->getKey())?->getKey())->toBe($this->own->getKey())
        ->and((new Thread)->resolveRouteBinding($this->foreign->getKey()))->toBeNull()
        // An explicit filter for another context finds nothing under the hook.
        ->and(Thread::query()->forContext($this->globex)->count())->toBe(0)
        ->and(Thread::query()->withoutContext()->count())->toBe(0);
});

it('lists, counts and relates only the current context\'s threads', function (): void {
    ($this->actAs)($this->acme);

    $foreignParticipant = Participant::query()->where('thread_id', $this->foreign->getKey())->firstOrFail();
    $ownParticipant = Participant::query()->where('thread_id', $this->own->getKey())->firstOrFail();

    expect(collect(Messages::inboxFor($this->bob)->items())->map->getKey()->all())->toBe([$this->own->getKey()])
        ->and(collect(Messages::threads($this->bob)->items())->map->getKey()->all())->toBe([$this->own->getKey()])
        ->and(Messages::inboxFor($this->bob, context: $this->globex)->total())->toBe(0)
        ->and($this->bob->threads()->map->getKey()->all())->toBe([$this->own->getKey()])
        ->and($this->bob->unreadThreads()->map->getKey()->all())->toBe([$this->own->getKey()])
        ->and(Messages::unreadCount($this->bob))->toBe(1)
        ->and($this->bob->unreadCount())->toBe(1)
        ->and(Message::query()->findOrFail($this->ownMessage->getKey())->thread?->getKey())->toBe($this->own->getKey())
        ->and(Message::query()->findOrFail($this->foreignMessage->getKey())->thread)->toBeNull()
        ->and($ownParticipant->thread?->getKey())->toBe($this->own->getKey())
        ->and($foreignParticipant->thread)->toBeNull();
});

it('refuses a held thread of another context on every manager path', function (): void {
    ($this->actAs)($this->acme);
    $foreign = $this->foreign;

    $refused = [
        'to' => fn () => Messages::to($foreign),
        'send' => fn () => Messages::send($foreign, $this->alice, 'leak'),
        'thread' => fn () => Messages::thread($foreign),
        'markRead' => fn () => Messages::markRead($foreign, $this->bob),
        'message' => fn () => Messages::message($this->foreignMessage),
        'unreadCount' => fn () => Messages::unreadCount($this->bob, $foreign),
        'prune' => fn () => Messages::prune(30, $foreign),
        'sendMessageTo' => fn () => $this->alice->sendMessageTo($foreign, 'leak'),
        'joinThread' => fn () => $this->alice->joinThread($foreign),
        'markThreadRead' => fn () => $this->bob->markThreadRead($foreign),
        'unreadCount (trait)' => fn () => $this->bob->unreadCount($foreign),
        'markReadFor' => fn () => $foreign->markReadFor($this->bob),
        'typing' => fn () => $foreign->typing($this->bob),
        'context-less thread' => fn () => Messages::thread($this->plain),
    ];

    foreach ($refused as $path => $call) {
        expect($call)->toThrow(ThreadContextException::class, 'Thread ['.($path === 'context-less thread' ? $this->plain->getKey() : $foreign->getKey()).'] belongs to another context.');
    }

    expect($this->foreign->messages()->count())->toBe(1);
});

it('allows every manager path on the current context\'s thread', function (): void {
    ($this->actAs)($this->acme);
    $own = $this->own;

    Messages::send($own, $this->alice, 'hello');
    $this->alice->sendMessageTo($own, 'again');
    Messages::thread($own)->rename('Acme renamed');
    Messages::message($this->ownMessage)->edit('acme, edited');
    $own->typing($this->bob);

    expect(Messages::unreadCount($this->bob, $own))->toBe(3)
        ->and(Messages::markRead($own, $this->bob)->last_read_message_id)->not->toBeNull()
        ->and($this->bob->unreadCount($own))->toBe(0)
        ->and(Messages::prune(30, $own))->toBe(0)
        ->and($own->fresh()?->name)->toBe('Acme renamed');
});

it('refuses a participant row whose thread is in another context', function (): void {
    ($this->actAs)($this->acme);

    $foreign = Participant::query()->where('thread_id', $this->foreign->getKey())->firstOrFail();
    $own = Participant::query()->where('thread_id', $this->own->getKey())->firstOrFail();

    expect(fn () => $foreign->markAsRead())->toThrow(ParticipationException::class)
        ->and($foreign->hasUnread())->toBeFalse()
        ->and($own->markAsRead()->last_read_message_id)->not->toBeNull();
});

it('starts threads in the current context, and refuses another one', function (): void {
    ($this->actAs)($this->acme);

    $implicit = Messages::start('Implicit')->withParticipant($this->alice)->create();
    $explicit = Messages::start('Explicit')->for($this->acme)->withParticipant($this->alice)->create();

    expect($implicit->isInContext($this->acme))->toBeTrue()
        ->and($explicit->isInContext($this->acme))->toBeTrue()
        ->and(Thread::find($implicit->getKey()))->not->toBeNull()
        ->and(fn () => Messages::start('Elsewhere')->for($this->globex)->withParticipant($this->alice)->create())
        ->toThrow(ThreadContextException::class, 'A thread can only be started in the current context.')
        ->and(fn () => $this->alice->startConversationWith($this->bob, 'Elsewhere', $this->globex))
        ->toThrow(ThreadContextException::class);

    expect(Messages::withoutContextScope(fn () => Thread::query()->where('name', 'Elsewhere')->count()))->toBe(0);
});

it('finds or creates the direct thread of the current context only', function (): void {
    Messages::withoutContextScope(fn () => Messages::direct($this->alice, $this->bob, $this->globex));
    ($this->actAs)($this->acme);

    $direct = Messages::direct($this->alice, $this->bob);

    expect($direct->isInContext($this->acme))->toBeTrue()
        ->and(Messages::direct($this->bob, $this->alice)->getKey())->toBe($direct->getKey())
        ->and(Messages::direct($this->alice, $this->bob, $this->acme)->getKey())->toBe($direct->getKey())
        ->and($this->bob->conversationWith($this->alice)->getKey())->toBe($direct->getKey())
        ->and(fn () => Messages::direct($this->alice, $this->bob, $this->globex))
        ->toThrow(ThreadContextException::class, 'A thread can only be started in the current context.')
        ->and(fn () => $this->alice->conversationWith($this->bob, $this->globex))
        ->toThrow(ThreadContextException::class);
});

it('switches off for a block of work with withoutContextScope()', function (): void {
    ($this->actAs)($this->acme);

    $all = Messages::withoutContextScope(fn (): int => Thread::query()->count());
    $sent = Messages::withoutContextScope(fn () => Messages::send($this->foreign, $this->alice, 'admin'));

    expect($all)->toBe(3)
        ->and($sent->thread_id)->toBe($this->foreign->getKey())
        // Back on afterwards — and after a block that throws.
        ->and(Thread::query()->count())->toBe(1);

    expect(fn () => Messages::withoutContextScope(fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);

    expect(Thread::query()->count())->toBe(1)
        ->and(Thread::withoutGlobalScope(ThreadContextScope::class)->count())->toBe(3);
});

it('removes the closure with resolveContextUsing(null)', function (): void {
    ($this->actAs)($this->acme);
    Messages::resolveContextUsing(null);

    expect(Messages::currentContext())->toBeNull()
        ->and(Thread::query()->count())->toBe(3);
});

it('applies no constraint while the resolver returns null', function (): void {
    ($this->actAs)(null);

    expect(Thread::query()->count())->toBe(3)
        ->and(Messages::thread($this->foreign)->model()->is($this->foreign))->toBeTrue();
});

it('reads a configured resolver class, which a closure overrides', function (): void {
    config()->set('messages.context.resolver', CurrentCompanyContext::class);

    CurrentCompanyContext::$current = $this->globex;

    expect(Thread::query()->pluck('id')->all())->toBe([$this->foreign->getKey()])
        ->and(fn () => Messages::thread($this->own))->toThrow(ThreadContextException::class);

    CurrentCompanyContext::$current = null;

    expect(Thread::query()->count())->toBe(3);

    CurrentCompanyContext::$current = $this->globex;
    ($this->actAs)($this->acme);

    expect(Thread::query()->pluck('id')->all())->toBe([$this->own->getKey()]);
});

it('fails closed on a resolver class that is not a ResolvesThreadContext', function (mixed $value): void {
    config()->set('messages.context.resolver', $value);

    expect(fn () => Thread::query()->count())
        ->toThrow(InvalidConfigurationException::class, 'messages.context.resolver');
})->with([
    'missing class' => ['App\\Tenancy\\Missing'],
    'not a resolver' => [User::class],
    'not a string' => [[CurrentCompanyContext::class]],
]);

it('reads a blank resolver class as off', function (mixed $value): void {
    config()->set('messages.context.resolver', $value);

    expect(Thread::query()->count())->toBe(3);
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['  '],
]);

it('fails closed on a resolver that returns a non-model or an unsaved one', function (): void {
    Messages::resolveContextUsing(static fn (): string => 'acme');

    expect(fn () => Thread::query()->count())
        ->toThrow(ThreadContextException::class, 'The thread context resolver must return a model or null, [string] given.');

    Messages::resolveContextUsing(static fn (): Company => new Company);

    expect(fn () => Thread::query()->count())->toThrow(ThreadContextException::class, 'A thread context must be a saved model.');
});

it('refuses the broadcast channel of another context\'s thread', function (): void {
    Broadcast::extend('authorising', static fn (): AuthorisingBroadcaster => new AuthorisingBroadcaster);
    config()->set('broadcasting.default', 'authorising');
    config()->set('broadcasting.connections.authorising', ['driver' => 'authorising']);
    Messages::channels()->register();
    ($this->actAs)($this->acme);

    expect(Broadcast::connection()->allows($this->bob, 'messaging.thread.'.$this->own->getKey()))->toBeTrue()
        ->and(Broadcast::connection()->allows($this->bob, 'messaging.thread.'.$this->foreign->getKey()))->toBeFalse();
});

it('keeps the hook under the fake: allowed calls are recorded, refused ones are not', function (): void {
    ($this->actAs)($this->acme);
    $fake = Messages::fake();

    expect(app(MessagesManager::class))->toBe($fake)->toBeInstanceOf(MessagesFake::class)
        ->and(Messages::currentContext()?->is($this->acme))->toBeTrue()
        ->and(fn () => Messages::send($this->foreign, $this->alice, 'leak'))->toThrow(ThreadContextException::class)
        ->and(fn () => $this->alice->sendMessageTo($this->foreign, 'leak'))->toThrow(ThreadContextException::class)
        ->and(fn () => Messages::direct($this->alice, $this->bob, $this->globex))->toThrow(ThreadContextException::class)
        ->and(fn () => Messages::start('Elsewhere')->for($this->globex)->create())->toThrow(ThreadContextException::class);

    Messages::assertNothingSent();
    Messages::assertNothingCreated();

    Messages::send($this->own, $this->alice, 'fine');
    $this->alice->conversationWith($this->bob);

    Messages::assertSent('fine', $this->own);
    Messages::assertThreadCreated(context: $this->acme);
});

it('reports whether the hook is set in the about section', function (?string $resolver, ?Closure $closure, string $expected): void {
    config()->set('messages.context.resolver', $resolver);
    Messages::resolveContextUsing($closure);

    Artisan::call('about', ['--only' => 'messages', '--json' => true]);

    /** @var array{messages: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true);

    expect($about['messages']['context_scope'])->toBe($expected);
})->with([
    'off' => [null, null, 'OFF'],
    'configured class' => [CurrentCompanyContext::class, null, 'ON'],
    'closure' => [null, static fn (): null => null, 'ON'],
    'invalid class' => [User::class, null, 'INVALID'],
]);
