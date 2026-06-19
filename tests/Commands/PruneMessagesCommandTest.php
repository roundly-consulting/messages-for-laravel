<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

it('prunes messages older than the given days', function () {
    $now = Carbon::now();
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    Carbon::setTestNow($now->copy()->subDays(100));
    messaging()->messages()->sendMessage($thread, $user, 'old');

    Carbon::setTestNow($now);
    messaging()->messages()->sendMessage($thread, $user, 'fresh');

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
    $thread = messaging()->threads()->create(name: 'Chat');

    Carbon::setTestNow($now->copy()->subDays(20));
    messaging()->messages()->sendMessage($thread, $user, 'old');
    Carbon::setTestNow($now);

    $this->artisan('messages:prune')->assertSuccessful();

    expect(Message::query()->withTrashed()->count())->toBe(0);

    Carbon::setTestNow();
});

it('limits pruning to a single thread', function () {
    $now = Carbon::now();
    $user = User::create();
    $keep = messaging()->threads()->create(name: 'Keep');
    $prune = messaging()->threads()->create(name: 'Prune');

    Carbon::setTestNow($now->copy()->subDays(100));
    messaging()->messages()->sendMessage($keep, $user, 'keep');
    messaging()->messages()->sendMessage($prune, $user, 'prune');
    Carbon::setTestNow($now);

    $this->artisan('messages:prune', ['--days' => 30, '--thread' => $prune->getKey()])
        ->assertSuccessful();

    expect(Message::query()->withTrashed()->count())->toBe(1)
        ->and(Message::query()->withTrashed()->first()->thread_id)->toBe($keep->getKey());

    Carbon::setTestNow();
});
