<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\Company;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    $this->alice = User::create();
    $this->bob = User::create();
});

it('exposes participations and threads relations', function () {
    $thread = $this->alice->startConversationWith($this->bob, name: 'Group');

    expect($this->alice->participations()->count())->toBe(1)
        ->and($this->alice->threads())->toHaveCount(1)
        ->and($this->alice->threads()->first()->getKey())->toBe($thread->getKey());
});

it('starts a group conversation including the initiator', function () {
    $carol = User::create();

    $thread = $this->alice->startConversationWith([$this->bob, $carol], name: 'Trio');

    expect($thread)->toBeInstanceOf(Thread::class)
        ->and($thread->participants()->count())->toBe(3);
});

it('finds or creates a direct conversation', function () {
    $first = $this->alice->conversationWith($this->bob);
    $second = $this->alice->conversationWith($this->bob);

    expect($second->getKey())->toBe($first->getKey())
        ->and($first->is_direct)->toBeTrue();
});

it('sends a message to a thread', function () {
    $thread = $this->alice->conversationWith($this->bob);

    $message = $this->alice->sendMessageTo($thread, 'Hi Bob!');

    expect($message->message)->toBe('Hi Bob!')
        ->and($message->sender_id)->toBe($this->alice->getKey());
});

it('tracks unread counts globally and per thread', function () {
    $thread = $this->alice->conversationWith($this->bob);
    $this->alice->sendMessageTo($thread, 'one');
    $this->alice->sendMessageTo($thread, 'two');

    expect($this->bob->unreadCount())->toBe(2)
        ->and($this->bob->unreadCount($thread))->toBe(2);
});

it('lists unread threads and marks them read', function () {
    $thread = $this->alice->conversationWith($this->bob);
    $this->alice->sendMessageTo($thread, 'hi');

    expect($this->bob->unreadThreads())->toHaveCount(1);

    $this->bob->markThreadRead($thread);

    expect($this->bob->unreadThreads())->toHaveCount(0)
        ->and($this->bob->unreadCount())->toBe(0);
});

it('joins an existing thread idempotently', function () {
    $thread = messaging()->threads()->create(name: 'Open');

    $this->alice->joinThread($thread);
    $this->alice->joinThread($thread);

    expect($thread->participants()->count())->toBe(1);
});

it('works for non-user participant models', function () {
    $company = Company::create();

    $thread = $company->conversationWith($this->alice);

    expect($thread->is_direct)->toBeTrue()
        ->and($company->threads())->toHaveCount(1);
});
