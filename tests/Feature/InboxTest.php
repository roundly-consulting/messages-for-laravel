<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    config()->set('messages.permissions.enabled', false);
});

it('paginates the inbox newest activity first with unread counts', function () {
    $reader = User::create();
    $sender = User::create();

    $older = $reader->startConversationWith($sender, 'Older');
    $newer = $reader->startConversationWith($sender, 'Newer');

    app(SendMessage::class)->execute(new SendMessageData($older, $sender, 'a'));
    app(SendMessage::class)->execute(new SendMessageData($newer, $sender, 'b'));
    app(SendMessage::class)->execute(new SendMessageData($newer, $sender, 'c'));

    $older->forceFill(['last_activity_at' => now()->subDay()])->save();
    $newer->forceFill(['last_activity_at' => now()])->save();

    $inbox = Messages::inboxFor($reader);

    expect($inbox->total())->toBe(2)
        ->and($inbox->items()[0]->getKey())->toBe($newer->getKey())
        ->and((int) $inbox->items()[0]->unread_count)->toBe(2)
        ->and((int) $inbox->items()[1]->unread_count)->toBe(1);
});

it('eager loads the inbox without N+1 queries', function () {
    $reader = User::create();
    $sender = User::create();

    foreach (range(1, 3) as $i) {
        $thread = $reader->startConversationWith($sender, "T{$i}");
        app(SendMessage::class)->execute(new SendMessageData($thread, $sender, "m{$i}"));
    }

    DB::enableQueryLog();
    $inbox = Messages::inboxFor($reader);

    // Touch eager-loaded relations so any lazy load would show up in the log.
    foreach ($inbox->items() as $thread) {
        $thread->latestMessagePreview();
        $thread->participants->each(fn ($p) => $p->participant);
        $count = $thread->unread_count;
    }
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Bounded: pagination count + page + eager loads, independent of thread count.
    expect(count($queries))->toBeLessThan(10);
});

it('supports inboxFor through the fake', function () {
    Messages::fake();

    $reader = User::create();
    $sender = User::create();
    $thread = $reader->startConversationWith($sender, 'Crew');
    app(SendMessage::class)->execute(new SendMessageData($thread, $sender, 'hi'));

    $inbox = Messages::inboxFor($reader);

    expect($inbox->total())->toBe(1);
});
