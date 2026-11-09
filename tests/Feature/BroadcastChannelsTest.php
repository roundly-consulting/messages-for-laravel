<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Handles\ChannelsHandle;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Tests\Fixtures\AuthorisingBroadcaster;
use RoundlyConsulting\Messages\Tests\Models\Admin\User as AdminUser;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * `{name}` is a class basename, so without a morph map `App\Models\User` 5 and
 * `App\Models\Admin\User` 5 share a per-participant channel. `{alias}` keeps them apart and,
 * unlike the deprecated `{type}`, never puts a class name in a channel a client subscribes to.
 * `Messages::channels()->register()` is the opt-in authorisation for every channel the package
 * broadcasts on.
 */
beforeEach(function (): void {
    Relation::morphMap([], false);

    Broadcast::extend('authorising', static fn (): AuthorisingBroadcaster => new AuthorisingBroadcaster);
    config()->set('broadcasting.default', 'authorising');
    config()->set('broadcasting.connections.authorising', ['driver' => 'authorising']);

    $this->broadcaster = Broadcast::connection();
    $this->alice = User::create();
    $this->bob = User::create();
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

function hashOf(string $class): string
{
    return substr(hash('sha256', $class), 0, 16);
}

it('names the participant channel through the facade and the injected manager', function (): void {
    expect(Messages::channels())->toBeInstanceOf(ChannelsHandle::class)
        ->and(Messages::channels()->participant($this->alice))->toBe('messaging.participant.user.'.$this->alice->getKey())
        ->and(app(MessagesManager::class)->channels()->participant($this->bob))->toBe('messaging.participant.user.'.$this->bob->getKey());
});

it('keeps same-basename classes apart on {alias} without a class name in the channel', function (): void {
    config()->set('messages.broadcasting.threads.per-participant-channel', 'messaging.participant.{alias}.{id}');
    $admin = AdminUser::create();

    $user = Messages::channels()->participant($this->alice);
    $other = Messages::channels()->participant($admin);

    expect($admin->getKey())->toBe($this->alice->getKey())
        ->and($user)->toBe('messaging.participant.'.hashOf(User::class).'.'.$this->alice->getKey())
        ->and($other)->toBe('messaging.participant.'.hashOf(AdminUser::class).'.'.$admin->getKey())
        ->and($user)->not->toBe($other)
        ->and($user.$other)->not->toContain('user', 'models', 'roundly', '\\');
});

it('uses the morph alias for {alias} when the type is one', function (): void {
    config()->set('messages.broadcasting.threads.per-participant-channel', 'messaging.participant.{alias}.{id}');
    Relation::morphMap(['diner' => User::class, 'staff' => AdminUser::class]);

    expect(Messages::channels()->participant($this->alice))->toBe('messaging.participant.diner.'.$this->alice->getKey())
        ->and(Messages::channels()->participant(AdminUser::create()))->toStartWith('messaging.participant.staff.');
});

it('hashes a morph alias a channel segment cannot hold', function (): void {
    config()->set('messages.broadcasting.threads.per-participant-channel', 'messaging.participant.{alias}.{id}');
    Relation::morphMap(['app.diner' => User::class]);

    expect(Messages::channels()->participant($this->alice))
        ->toBe('messaging.participant.'.hashOf('app.diner').'.'.$this->alice->getKey());
});

it('names the thread channels, once each', function (): void {
    $thread = Messages::start('Team')->withParticipants([$this->alice])->create();

    expect(Messages::channels()->thread($thread))->toBe(['messaging.thread.'.$thread->getKey()]);

    config()->set('messages.broadcasting.participants.channel', 'messaging.roster.{id}');

    expect(Messages::channels()->thread($thread))->toBe([
        'messaging.thread.'.$thread->getKey(),
        'messaging.roster.'.$thread->getKey(),
    ]);
});

it('admits only current participants to a thread channel', function (): void {
    $thread = Messages::start('Team')->withParticipants([$this->alice, $this->bob])->create();
    $outsider = User::create();
    Messages::channels()->register();

    [$channel] = Messages::channels()->thread($thread);

    expect($this->broadcaster->allows($this->alice, $channel))->toBeTrue()
        ->and($this->broadcaster->allows($outsider, $channel))->toBeFalse();

    Messages::thread($thread)->participants()->leave($this->bob);

    expect($this->broadcaster->allows($this->bob, $channel))->toBeFalse();
});

it('refuses an unknown thread, a junk id and a user that is not a model', function (): void {
    $thread = Messages::start('Team')->withParticipants([$this->alice])->create();
    Messages::channels()->register();

    DB::enableQueryLog();

    expect($this->broadcaster->allows($this->alice, 'messaging.thread.999999'))->toBeFalse()
        ->and($this->broadcaster->allows($this->alice, 'messaging.thread.abc'))->toBeFalse()
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and($this->broadcaster->allows(new GenericUser(['id' => $this->alice->getKey()]), 'messaging.thread.'.$thread->getKey()))->toBeFalse()
        ->and($this->broadcaster->allows(new GenericUser(['id' => $this->alice->getKey()]), Messages::channels()->participant($this->alice)))->toBeFalse();
});

it('admits a participant to their own channel only', function (): void {
    config()->set('messages.broadcasting.threads.per-participant-channel', 'messaging.participant.{alias}.{id}');
    Messages::channels()->register();
    $admin = AdminUser::create();

    expect($this->broadcaster->allows($this->alice, Messages::channels()->participant($this->alice)))->toBeTrue()
        ->and($this->broadcaster->allows($this->bob, Messages::channels()->participant($this->alice)))->toBeFalse()
        ->and($this->broadcaster->allows($admin, Messages::channels()->participant($this->alice)))->toBeFalse()
        ->and($this->broadcaster->allows($admin, Messages::channels()->participant($admin)))->toBeTrue();
});

it('lets same-basename classes into each other\'s {name} channel, as documented', function (): void {
    Messages::channels()->register();
    $admin = AdminUser::create();

    expect($this->broadcaster->allows($this->alice, Messages::channels()->participant($this->alice)))->toBeTrue()
        ->and($this->broadcaster->allows($admin, Messages::channels()->participant($this->alice)))->toBeTrue();
});

it('cannot authorise the deprecated {type} channel', function (): void {
    config()->set('messages.broadcasting.threads.per-participant-channel', 'messaging.participant.{type}.{id}');
    Messages::channels()->register();

    expect($this->broadcaster->allows($this->alice, Messages::channels()->participant($this->alice)))->toBeFalse();
});

it('authorises exactly the channels new private threads go out on', function (): void {
    config()->set('messages.broadcasting.enabled', true);
    config()->set('messages.broadcasting.threads.per-participant-channel', 'messaging.participant.{alias}.{id}');
    Messages::channels()->register();

    $thread = Messages::start('Secret')->private()->withParticipants([$this->alice, $this->bob])->create();

    $channels = array_map(
        static fn ($channel): string => str($channel->name)->after('private-')->toString(),
        $thread->broadcastOn('created'),
    );

    expect($channels)->toBe([
        Messages::channels()->participant($this->alice),
        Messages::channels()->participant($this->bob),
    ])
        ->and($this->broadcaster->allows($this->alice, $channels[0]))->toBeTrue()
        ->and($this->broadcaster->allows($this->alice, $channels[1]))->toBeFalse();
});

it('skips a thread channel that names no thread', function (): void {
    config()->set('messages.broadcasting.messages.channel', 'messaging.everyone');
    Messages::channels()->register();
    $thread = Messages::start('Team')->withParticipants([$this->alice])->create();

    expect($this->broadcaster->getChannels()->keys()->all())->toBe([
        'messaging.thread.{id}',
        'messaging.participant.{name}.{id}',
    ])
        ->and($this->broadcaster->allows($this->alice, 'messaging.everyone'))->toBeFalse()
        ->and($this->broadcaster->allows($this->alice, 'messaging.thread.'.$thread->getKey()))->toBeTrue();
});

it('authenticates through the given guards', function (): void {
    Messages::channels()->register(['web', 'admin']);
    $thread = Messages::start('Team')->withParticipants([$this->alice])->create();

    expect($this->broadcaster->allows($this->alice, 'messaging.thread.'.$thread->getKey()))->toBeTrue()
        ->and($this->broadcaster->guardsAsked)->toBe(['web'])
        ->and($this->broadcaster->optionsFor('messaging.thread.'.$thread->getKey()))->toBe(['guards' => ['web', 'admin']]);
});

it('fails closed when a host binding turns a channel value into an object', function (): void {
    Messages::channels()->register();
    $thread = Messages::start('Team')->withParticipants([$this->alice])->create();
    app('router')->bind('id', static fn (string $value): object => (object) ['id' => $value]);

    expect($this->broadcaster->allows($this->alice, 'messaging.thread.'.$thread->getKey()))->toBeFalse()
        ->and($this->broadcaster->allows($this->alice, Messages::channels()->participant($this->alice)))->toBeFalse();
});

it('works under the fake', function (): void {
    Messages::fake();
    Messages::channels()->register();
    $thread = Messages::start('Team')->withParticipants([$this->alice])->create();

    expect($this->broadcaster->allows($this->alice, Messages::channels()->thread($thread)[0]))->toBeTrue();
});
