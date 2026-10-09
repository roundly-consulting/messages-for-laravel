<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

it('prunes messages older than the given days', function () {
    $now = Carbon::now();
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    Carbon::setTestNow($now->copy()->subDays(100));
    Messages::send($thread, $user, 'old');

    Carbon::setTestNow($now);
    Messages::send($thread, $user, 'fresh');

    $this->artisan('messages:prune', ['--days' => 30])
        ->expectsOutputToContain('Pruned 1 message(s)')
        ->assertSuccessful();

    expect(Message::query()->withTrashed()->count())->toBe(1);

    Carbon::setTestNow();
});

it('falls back to the configured retention window', function () {
    config()->set('messages.prune.days', 10);
    $now = Carbon::now();
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    Carbon::setTestNow($now->copy()->subDays(20));
    Messages::send($thread, $user, 'old');
    Carbon::setTestNow($now);

    $this->artisan('messages:prune')->assertSuccessful();

    expect(Message::query()->withTrashed()->count())->toBe(0);

    Carbon::setTestNow();
});

it('limits pruning to a single thread', function () {
    $now = Carbon::now();
    $user = User::create();
    $keep = Messages::start('Keep')->withParticipant($user)->create();
    $prune = Messages::start('Prune')->withParticipant($user)->create();

    Carbon::setTestNow($now->copy()->subDays(100));
    Messages::send($keep, $user, 'keep');
    Messages::send($prune, $user, 'prune');
    Carbon::setTestNow($now);

    $this->artisan('messages:prune', ['--days' => 30, '--thread' => $prune->getKey()])
        ->assertSuccessful();

    expect(Message::query()->withTrashed()->count())->toBe(1)
        ->and(Message::query()->withTrashed()->first()->thread_id)->toBe($keep->getKey());

    Carbon::setTestNow();
});

/**
 * `--days=0` / `--days=-1` pruned everything (the cutoff at or after now), `--days=ten`
 * silently used the configured window, and `--days=1.9` silently meant 1. Each is refused,
 * naming the option, before anything is deleted.
 */
it('refuses a --days value that is not a whole number of at least 1', function (string $days) {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    Carbon::setTestNow(Carbon::now()->subDays(100));
    Messages::send($thread, $user, 'old');
    Carbon::setTestNow();
    Messages::send($thread, $user, 'fresh');

    $this->artisan('messages:prune', ['--days' => $days])
        ->expectsOutputToContain('--days')
        ->assertFailed();

    expect(Message::query()->withTrashed()->count())->toBe(2);
})->with(['0', '-1', 'ten', '1.9', '']);

it('accepts --days=1', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    Carbon::setTestNow(Carbon::now()->subDays(2));
    Messages::send($thread, $user, 'old');
    Carbon::setTestNow();
    Messages::send($thread, $user, 'fresh');

    $this->artisan('messages:prune', ['--days' => '1'])
        ->expectsOutputToContain('Pruned 1 message(s) older than 1 day(s).')
        ->assertSuccessful();

    expect(Message::query()->pluck('message')->all())->toBe(['fresh']);
});
