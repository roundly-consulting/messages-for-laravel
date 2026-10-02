<?php

declare(strict_types=1);

use App\Http\Resources\Messages\MessageResource as PublishedMessageResource;
use App\Http\Resources\Messages\ParticipantResource as PublishedParticipantResource;
use App\Http\Resources\Messages\ThreadResource as PublishedThreadResource;
use App\Notifications\Messages\NewMessageNotification as PublishedNewMessageNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Http\Resources\MessageResource;
use RoundlyConsulting\Messages\Http\Resources\ParticipantResource;
use RoundlyConsulting\Messages\Http\Resources\ThreadResource;
use RoundlyConsulting\Messages\MessagesServiceProvider;
use RoundlyConsulting\Messages\Notifications\NewMessageNotification;
use RoundlyConsulting\Messages\Tests\Models\User;

/*
 * `messages-resources` and `messages-notifications` used to copy the package's own classes into
 * app/, namespace and all: under `app/` they broke PSR-4 and were never loaded, so editing them
 * changed nothing. The tags now publish host-owned classes in `App\Http\Resources\Messages` and
 * `App\Notifications\Messages`, behaving exactly like the package classes until the host edits
 * them.
 */

/**
 * A publish tag's map, keyed by destination basename.
 *
 * @return array<string, array{from: string, to: string}>
 */
function publishedStubs(string $tag): array
{
    $files = [];

    foreach (ServiceProvider::pathsToPublish(MessagesServiceProvider::class, $tag) as $from => $to) {
        $files[basename((string) $to)] = ['from' => (string) $from, 'to' => (string) $to];
    }

    return $files;
}

function requirePublishedStubs(): void
{
    foreach (['messages-resources', 'messages-notifications'] as $tag) {
        foreach (publishedStubs($tag) as $file) {
            require_once $file['from'];
        }
    }
}

it('publishes each class as its own file under app/', function (string $tag, string $directory, array $files): void {
    $published = publishedStubs($tag);

    expect(array_keys($published))->toEqualCanonicalizing($files);

    foreach ($published as $file) {
        expect(dirname($file['to']))->toBe(app_path($directory))
            ->and(is_file($file['from']))->toBeTrue();
    }
})->with([
    'resources' => ['messages-resources', 'Http/Resources/Messages', ['ThreadResource.php', 'MessageResource.php', 'ParticipantResource.php']],
    'notifications' => ['messages-notifications', 'Notifications/Messages', ['NewMessageNotification.php']],
]);

it('publishes classes in the app namespace, not the package one', function (string $tag, string $namespace): void {
    foreach (publishedStubs($tag) as $name => $file) {
        $source = (string) file_get_contents($file['from']);

        expect($source)->toContain("namespace {$namespace};")
            ->not->toContain('namespace RoundlyConsulting')
            ->toContain('class '.basename($name, '.php').' ');
    }
})->with([
    'resources' => ['messages-resources', 'App\\Http\\Resources\\Messages'],
    'notifications' => ['messages-notifications', 'App\\Notifications\\Messages'],
]);

it('renders exactly what the package resources render until the host edits them', function (): void {
    requirePublishedStubs();
    config()->set('messages.permissions.enabled', true);

    $alice = User::create();
    $bob = User::create();
    $thread = Messages::start('Launch')->withParticipants([$alice, $bob])->create();
    $original = Messages::send($thread, $alice, 'First');
    Messages::to($thread)->from($bob)->replyingTo($original)->send('On it');
    Messages::message($original)->edit('First, edited', by: $alice);
    $bob->markThreadRead($thread);

    $request = Request::create('/');
    $json = fn (JsonResource $resource): mixed => json_decode((string) $resource->toResponse($request)->getContent(), true);

    $inbox = Messages::inboxFor($bob);
    $messages = Messages::thread($thread)->messages();
    $participant = $thread->participants()->with('participant')->firstOrFail();

    expect($json(PublishedThreadResource::collection($inbox)))->toBe($json(ThreadResource::collection($inbox)))
        ->and($json(PublishedThreadResource::collection($inbox))['data'][0]['latest_message']['reply_to'])->not->toBeNull()
        ->and($json(PublishedMessageResource::collection($messages)))->toBe($json(MessageResource::collection($messages)))
        ->and($json(new PublishedParticipantResource($participant)))->toBe($json(new ParticipantResource($participant)));
});

it('notifies exactly like the package notification until the host edits it', function (): void {
    requirePublishedStubs();
    config()->set('messages.notifications.channels', ['database', 'broadcast']);

    $alice = User::create();
    $bob = User::create();
    $message = Messages::send(Messages::direct($alice, $bob), $alice, 'Hello');

    $published = new PublishedNewMessageNotification($message);
    $package = new NewMessageNotification($message);

    expect($published)->toBeInstanceOf(ShouldQueue::class)
        ->and($published->via($bob))->toBe($package->via($bob))
        ->and($published->toArray($bob))->toBe($package->toArray($bob));
});
