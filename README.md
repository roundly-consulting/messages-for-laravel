# Messages for Laravel

Realtime messages between any Eloquent entities, built on Laravel's native model
broadcasting. Create threads, add participants of any model type via polymorphic relations,
send messages, and broadcast every change over private and public channels — with zero
realtime cost until you switch broadcasting on.

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

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="messages-config"
```

The package ships three tables: `messaging_threads`, `messaging_participants`, and
`messaging_messages`. All use UUID primary keys and soft deletes.

## Preparing your models

Any model that takes part in messaging — as a sender or a participant — must implement
`RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging`. The single
`participateAs()` method returns the array that is broadcast to represent that entity.

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

class User extends Model implements ParticipatesInMessaging
{
    public function participateAs(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
```

Because senders and participants are polymorphic, different model types (e.g. a `User` and a
`Company`) can share the same thread.

## Configuration

The published `config/messages.php` controls the models used, default thread visibility, and
broadcasting.

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
| `broadcasting.enabled` | bool | `false` | `REALTIME_MESSAGES` |
| `broadcasting.*.channel` / `*.public-channel` / `*.per-participant-channel` | string | see config | — |
| `broadcasting.*.events.*` | string | see config | — |

Swap any of the `models.*` entries for your own subclass to extend behaviour.

## Usage

Resolve the messaging service through dependency injection, the container, or the global
`messaging()` helper.

```php
use RoundlyConsulting\Messages\MessagingService;

public function handle(MessagingService $messaging): void
{
    $messaging->threads()->create(name: 'General');
}

// or, anywhere:
messaging()->threads()->create(name: 'General');
```

### Threads

A thread has a name and two visibility flags. When omitted, the flags fall back to the
`publicity` config defaults.

| `isPublic` | `everyoneCanJoin` | Behaviour |
|---|---|---|
| `false` | `false` | Private thread — only invited participants can read and write |
| `true` | `false` | Public read, invited-only write |
| `true` | `true` | Open thread — anyone can read and join |

```php
$thread = messaging()->threads()->create(
    name: 'Hello everyone!',
    isPublic: false,
    everyoneCanJoin: false,
);

// Paginate public threads, or threads a given participant belongs to:
$publicThreads = messaging()->threads()->paginate();
$myThreads = messaging()->threads()->paginate(participant: $user);
```

### Participants

```php
$participant = messaging()->participants()->addParticipantToThread(
    thread: $thread,
    participant: $user,
);
```

Adding a participant touches the thread's `last_activity_at` timestamp.

### Messages

```php
$message = messaging()->messages()->sendMessage(
    thread: $thread,
    sender: $user,
    message: 'Hi guys, this is my first message!',
);

$messages = messaging()->messages()->paginate(thread: $thread, perPage: 25);
```

## Broadcasting

Broadcasting is **off by default** — nothing is broadcast until you enable it:

```dotenv
REALTIME_MESSAGES=true
```

or at runtime:

```php
config()->set('messages.broadcasting.enabled', true);
```

When enabled, the package's models use Laravel's `BroadcastsEvents` to emit events on
create/update/delete/restore. Channel and event names are fully configurable in
`config/messages.php`.

### Channels

- `messaging` — public thread channel.
- `messaging.participant.{name}.{id}` — private per-participant channel for private threads
  (`{name}` is the lower-cased class basename of the participant, e.g. `user`).
- `messaging.thread.{id}` — per-thread channel that carries participant and message events.

### Events (defaults)

| Subject | Events |
|---|---|
| Thread | `messaging.thread.created` |
| Participant | `messaging.participant.joined`, `messaging.participant.read`, `messaging.participant.left` |
| Message | `messaging.message.sent`, `messaging.message.updated`, `messaging.message.unsent`, `messaging.message.restored` |

Each broadcast payload is the array returned by the model's `broadcastWith()` — see the
config file for the full mapping of events to names.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
