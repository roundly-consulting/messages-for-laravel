<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

it('prunes messages older than the given days', function () {
    $now = Carbon::now();
    $user = User::create();
    $thread = Messages::start('Chat')->create();

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
    $thread = Messages::start('Chat')->create();

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
    $keep = Messages::start('Keep')->create();
    $prune = Messages::start('Prune')->create();

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
