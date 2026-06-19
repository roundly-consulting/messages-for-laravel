# Messages for Laravel

A direct-message and group-chat foundation for any Laravel app. Give any Eloquent model a
one-line messaging API, track read receipts and unread counts, react to plain Laravel events,
and (optionally) broadcast every change in real time — all built only on Laravel/Symfony, with
no third-party runtime dependencies.

## Requirements

- PHP `^8.4`
- Laravel `^12.0` or `^13.0`

## Installation

```bash
composer require roundly-consulting/messages-for-laravel
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="messages-migrations"
php artisan migrate
```

Optionally publish the config or translations:

```bash
php artisan vendor:publish --tag="messages-config"
php artisan vendor:publish --tag="messages-translations"
```

The package ships three tables — `messaging_threads`, `messaging_participants`, and
`messaging_messages` — all with UUID primary keys and soft deletes.

## Preparing your models

Any model that takes part in messaging (a sender or a participant) must implement
`RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging`. Add the `HasMessaging` trait
to get the ergonomic API.

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Concerns\HasMessaging;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

class User extends Model implements ParticipatesInMessaging
{
    use HasMessaging;

    public function participateAs(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
```

Participation is polymorphic, so different model types (a `User` and a `Company`) can share the
same conversation.

## Direct messages (1:1)

The most common case is a single line:

```php
$thread = $alice->conversationWith($bob); // find-or-create the DM
$alice->sendMessageTo($thread, 'Hi Bob!');

$bob->unreadCount();          // 1
$bob->markThreadRead($thread);
$bob->unreadCount();          // 0
```

`conversationWith()` always returns the *same* direct thread for the same two participants, so
you never end up with duplicate DMs. Direct threads are always private.

## Group conversations

```php
$thread = $alice->startConversationWith([$bob, $carol], name: 'Project X');
$alice->sendMessageTo($thread, 'Welcome everyone');

$alice->threads();        // every thread $alice is in, newest activity first
$alice->unreadThreads();  // only threads with unread messages
$alice->joinThread($thread);
```

## The `Messages` facade

A fluent facade is layered over the same logic for call-site ergonomics.

```php
use RoundlyConsulting\Messages\Facades\Messages;

// Fluent thread creation
$thread = Messages::thread('Project X')
    ->public()                 // ->private(), ->direct()
    ->everyoneCanJoin()
    ->withParticipants([$alice, $bob])
    ->create();

// Fluent message send
Messages::to($thread)->from($alice)->send('Hello');

// Direct passthroughs
Messages::direct($alice, $bob);          // find-or-create a DM
Messages::send($thread, $alice, 'Hello');
Messages::markRead($thread, $bob);
Messages::unreadCount($bob);             // global, or pass a thread
```

The alias `Messages` is auto-registered; you can also import the FQCN to avoid any clash.

## Read receipts & unread counts

```php
$thread->unreadCountFor($user);   // messages $user hasn't read (excludes their own)
$thread->seenBy($message);        // participants whose read pointer is at/after $message

$participant->hasUnread();
$participant->markAsRead();
```

A message is unread for a participant when it was created after their last-read pointer (or
they have never read the thread) and they are not its sender.

## Editing, unsending & system messages

```php
use RoundlyConsulting\Messages\Actions\EditMessage;
use RoundlyConsulting\Messages\Actions\DeleteMessage;
use RoundlyConsulting\Messages\DataTransferObjects\EditMessageData;

app(EditMessage::class)->execute(new EditMessageData($message, 'edited body'));
app(DeleteMessage::class)->execute($message); // soft delete ("unsent")
```

Editing or deleting an already-deleted message throws a typed
`RoundlyConsulting\Messages\Exceptions\MessageException`.

Enable system messages (e.g. "Alice joined") with `messages.system-messages.enabled`. They are
stored as a `MessageType::System` message with a translation key as the body and the parameters
in the `meta` JSON column, so they render correctly in any locale.

## The action & DTO layer

All writes funnel through small, container-resolvable actions that accept DTOs and dispatch the
matching event. Advanced consumers can use them directly:

| Action | DTO |
|---|---|
| `StartThread` | `CreateThreadData` |
| `SendMessage` | `SendMessageData` |
| `AddParticipant` / `RemoveParticipant` / `LeaveThread` | `AddParticipantData` |
| `MarkRead` | `MarkReadData` |
| `EditMessage` | `EditMessageData` |
| `DeleteMessage` | `Message` |
| `FindOrCreateDirectThread` | two models |

## Events

Plain Laravel events fire from every write path **regardless of whether broadcasting is on**,
so non-broadcasting apps can still react (notifications, search indexing, activity logs):

`ThreadCreated`, `MessageSent`, `MessageEdited`, `MessageDeleted`, `ParticipantJoined`,
`ParticipantLeft`, `ThreadRead` (all under `RoundlyConsulting\Messages\Events`).

```php
Event::listen(MessageSent::class, function (MessageSent $event): void {
    // $event->message
});
```

## Query scopes

```php
Thread::query()->direct();
Thread::query()->forParticipant($user);
Thread::query()->between($alice, $bob);
Message::query()->unreadFor($user);
Participant::query()->unread();
```

## Low-level service (backward compatible)

The original `messaging()` helper, `MessagingService`, and the three repositories still work
unchanged and now also dispatch the events above:

```php
$thread = messaging()->threads()->create(name: 'General', isPublic: true);
messaging()->participants()->addParticipantToThread($thread, $user);
messaging()->messages()->sendMessage($thread, $user, 'Hi!');
$messages = messaging()->messages()->paginate(thread: $thread, perPage: 25);
```

## Configuration

```php
return [
    'models' => [
        'message' => RoundlyConsulting\Messages\Models\Message::class,
        'thread' => RoundlyConsulting\Messages\Models\Thread::class,
        'participant' => RoundlyConsulting\Messages\Models\Participant::class,
    ],

    'publicity' => [
        'public-by-default' => env('THREADS_PUBLIC', false),
        'everyone-can-join' => env('THREADS_EVERYONE_CAN_JOIN', false),
    ],

    'system-messages' => [
        'enabled' => env('MESSAGES_SYSTEM_MESSAGES', false),
    ],

    'prune' => [
        'days' => env('MESSAGES_PRUNE_DAYS', 90),
    ],

    'broadcasting' => [
        'enabled' => env('REALTIME_MESSAGES', false),
        // channels + event names for threads, participants and messages
    ],
];
```

| Key | Type | Default | Backed by |
|---|---|---|---|
| `models.message` / `models.thread` / `models.participant` | class-string | the package models | — |
| `publicity.public-by-default` | bool | `false` | `THREADS_PUBLIC` |
| `publicity.everyone-can-join` | bool | `false` | `THREADS_EVERYONE_CAN_JOIN` |
| `system-messages.enabled` | bool | `false` | `MESSAGES_SYSTEM_MESSAGES` |
| `prune.days` | int | `90` | `MESSAGES_PRUNE_DAYS` |
| `broadcasting.enabled` | bool | `false` | `REALTIME_MESSAGES` |
| `broadcasting.*.channel` / `*.events.*` | string | see config | — |

Swap any `models.*` entry for your own subclass to extend behaviour.

## Broadcasting

Broadcasting is **off by default** — the plain events above still fire either way. Turn it on
to push changes live over Laravel broadcasting (Reverb, Pusher, Ably, …):

```dotenv
REALTIME_MESSAGES=true
```

When enabled, the models use Laravel's `BroadcastsEvents` on create/update/delete/restore.
Channel and event names are fully configurable.

- `messaging` — public thread channel.
- `messaging.participant.{name}.{id}` — private per-participant channel for private threads.
- `messaging.thread.{id}` — per-thread channel for participant and message events.

## Pruning old messages

```bash
php artisan messages:prune --days=30          # defaults to MESSAGES_PRUNE_DAYS
php artisan messages:prune --thread={uuid}    # limit to one thread
```

## Testing helpers

```php
use RoundlyConsulting\Messages\Facades\Messages;

$fake = Messages::fake();

Messages::send($thread, $user, 'Hi');

$fake->assertSent('Hi');
$fake->assertSentCount(1);
```

Factories ship handy states: `Thread::factory()->direct()/public()/private()`,
`Message::factory()->system()`, and `Participant::factory()->read()/unread()`.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
