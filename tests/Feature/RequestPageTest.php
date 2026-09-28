<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Http\Resources\MessageResource;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * The listings are paginators an API returns as-is, so — like Eloquent's own `paginate()` —
 * they must read the page from the request when the caller does not pass one. Before this,
 * `page` defaulted to 1, `?page=2` was ignored, and an infinite-scroll client following the
 * `next` link looped on page one forever.
 */
beforeEach(function () {
    config()->set('messages.permissions.enabled', false);

    $this->alice = User::create();
    $this->bob = User::create();

    $this->group = Messages::start('Group')->withParticipants([$this->alice, $this->bob])->create();

    foreach (range(1, 5) as $i) {
        Messages::send($this->group, $this->alice, "m{$i}");
    }

    foreach (range(1, 3) as $i) {
        Messages::start("T{$i}")->withParticipants([$this->bob])->create();
    }
});

function onRequest(string $uri): void
{
    app()->instance('request', Request::create($uri, 'GET'));
}

it('reads ?page= for a thread\'s messages', function () {
    onRequest('/messages?page=2');

    $page = Messages::thread($this->group)->messages(perPage: 2);

    expect($page->currentPage())->toBe(2)
        ->and(collect($page->items())->pluck('message')->all())->toBe(['m3', 'm2']);
});

it('reads ?page= for the inbox', function () {
    onRequest('/inbox?page=2');

    $page = Messages::inboxFor($this->bob, perPage: 2);

    expect($page->currentPage())->toBe(2)
        ->and(collect($page->items())->pluck('name')->all())->toBe(['T1', 'Group']);
});

it('reads ?page= for the thread listing', function () {
    onRequest('/threads?page=2');

    expect(Messages::threads($this->bob, perPage: 2)->currentPage())->toBe(2);
});

it('reads a custom page name from the request', function () {
    onRequest('/inbox?threads=2&page=9');

    expect(Messages::inboxFor($this->bob, perPage: 2, pageName: 'threads')->currentPage())->toBe(2)
        ->and(Messages::thread($this->group)->messages(perPage: 2, pageName: 'threads')->currentPage())->toBe(2);
});

it('lets an explicit page win over the request', function () {
    onRequest('/inbox?page=2');

    expect(Messages::inboxFor($this->bob, perPage: 2, page: 1)->currentPage())->toBe(1)
        ->and(Messages::thread($this->group)->messages(perPage: 2, page: 3)->currentPage())->toBe(3);
});

it('renders a resource envelope whose current page matches its links', function () {
    onRequest('/messages?page=2');

    $body = MessageResource::collection(Messages::thread($this->group)->messages(perPage: 2))
        ->toResponse(request())
        ->getData(true);

    expect($body['meta']['current_page'])->toBe(2)
        ->and($body['links']['next'])->toEndWith('?page=3');
});
