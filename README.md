<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/messages-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=messages-for-laravel">
    <img src="art/hero.png" alt="Messages for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

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
$alice->conversations();  // alias of threads() with a chat-inbox name
$alice->unreadThreads();  // only threads with unread messages
$alice->joinThread($thread);
```

## Participant roles & permissions

Group threads carry per-participant roles — **owner**, **admin**, **member**
(`RoundlyConsulting\Messages\Enums\ParticipantRole`). The participant who starts a thread is
its owner; everyone added afterwards joins as a member. Owners and admins may add/remove
participants, rename and archive the thread, and moderate anyone's messages; members may only
manage their own messages.

```php
use RoundlyConsulting\Messages\Actions\SetParticipantRole;
use RoundlyConsulting\Messages\Actions\TransferOwnership;
use RoundlyConsulting\Messages\DataTransferObjects\SetParticipantRoleData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;

$thread->roleOf($bob);            // ParticipantRole::Member|Admin|Owner|null
$thread->canManage($bob);         // bool

app(SetParticipantRole::class)->execute(new SetParticipantRoleData(
    thread: $thread, participant: $bob, role: ParticipantRole::Admin, actor: $alice,
));

app(TransferOwnership::class)->execute($thread, $alice, $bob); // $alice is demoted to admin
```

Enforcement is opt-out via `messages.permissions.enabled` (default `true`) and is **skipped for
direct threads**, which are always roleless. When an actor lacks the required role the action
throws a typed `RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction`. Passing no
`actor` skips the check, so trusted server-side code keeps working unchanged.

Renaming and archiving are first-class actions:

```php
use RoundlyConsulting\Messages\Actions\RenameThread;
use RoundlyConsulting\Messages\Actions\ArchiveThread;

app(RenameThread::class)->execute($thread, 'New name', $alice);
app(ArchiveThread::class)->execute($thread, $alice);
$thread->isArchived();   // true
```

## Replies & quoting

Any message can reply to another in the same thread. A lightweight snapshot of the quoted
message is stored on the reply, so the quote survives the parent being edited or deleted.

```php
use RoundlyConsulting\Messages\Facades\Messages;

$reply = Messages::to($thread)->from($bob)->replyingTo($original)->send('On it!');

$reply->parent;       // the quoted message (relation)
$original->replies;   // every reply to it
$reply->meta['quote']; // ['id', 'sender_id', 'sender_type', 'excerpt'] snapshot
```

Replying to a message in a different thread throws a `MessageException`.

## Notifications

Opt in to bridge new messages to Laravel notifications. When enabled, every sent message
notifies the thread's other notifiable participants (the sender is never notified, and
non-notifiable participants are skipped).

```dotenv
MESSAGES_NOTIFICATIONS=true
```

```php
// config/messages.php
'notifications' => [
    'enabled' => env('MESSAGES_NOTIFICATIONS', false),
    'notification' => RoundlyConsulting\Messages\Notifications\NewMessageNotification::class,
    'channels' => ['database'],   // mail is never forced
],
```

Point `notifications.notification` at your own class (or publish the default with
`vendor:publish --tag=messages-notifications`) to customise channels and content.

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
Messages::between($alice, $bob);         // alias of direct()
Messages::send($thread, $alice, 'Hello');
Messages::markRead($thread, $bob);
Messages::unreadCount($bob);             // global, or pass a thread
Messages::inboxFor($bob);                // see "Inbox" below
```

## Inbox

`Messages::inboxFor()` returns a paginator of the participant's threads — newest activity first
— with the latest message (and its sender), participants, and a per-thread `unread_count`
eager-loaded, so a chat sidebar renders without N+1 queries.

```php
$inbox = Messages::inboxFor($bob, perPage: 20);

foreach ($inbox as $thread) {
    $thread->unread_count;            // integer
    $thread->latestMessagePreview();  // short, type-aware preview string
}
```

A few thread helpers complement it:

```php
$thread->markReadFor($bob);          // mark read on behalf of a participant
$thread->latestMessagePreview();     // null when the thread is empty
$message->isReadBy($bob);            // has $bob read up to this message?
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
| `DeleteMessage` | `Message` (+ optional actor) |
| `FindOrCreateDirectThread` | two models |
| `SetParticipantRole` | `SetParticipantRoleData` |
| `TransferOwnership` | thread + current/new owner |
| `RenameThread` / `ArchiveThread` | thread (+ optional actor) |

## Events

Plain Laravel events fire from every write path **regardless of whether broadcasting is on**,
so non-broadcasting apps can still react (notifications, search indexing, activity logs):

`ThreadCreated`, `MessageSent`, `MessageEdited`, `MessageDeleted`, `ParticipantJoined`,
`ParticipantLeft`, `ThreadRead`, `ThreadRenamed`, `ThreadArchived`, and `ParticipantTyping`
(all under `RoundlyConsulting\Messages\Events`).

`ParticipantTyping` is a transient, never-persisted signal you can broadcast for live typing
indicators — see Broadcasting below.

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

## API resources

JSON resources are provided for building a messaging API or an Inertia/SPA backend. They expose
only safe fields, render read-state and reply info, and degrade gracefully when relations are not
eager-loaded.

```php
use RoundlyConsulting\Messages\Http\Resources\ThreadResource;
use RoundlyConsulting\Messages\Http\Resources\MessageResource;
use RoundlyConsulting\Messages\Http\Resources\ParticipantResource;

return ThreadResource::collection(Messages::inboxFor($request->user()));
return MessageResource::collection($thread->messages()->with('sender')->paginate());
```

Publish them into your app to customise (`vendor:publish --tag=messages-resources`).

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

    'permissions' => [
        'enabled' => env('MESSAGES_PERMISSIONS', true),
    ],

    'notifications' => [
        'enabled' => env('MESSAGES_NOTIFICATIONS', false),
        'notification' => RoundlyConsulting\Messages\Notifications\NewMessageNotification::class,
        'channels' => ['database'],
    ],

    'preview' => [
        'length' => env('MESSAGES_PREVIEW_LENGTH', 120),
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
| `permissions.enabled` | bool | `true` | `MESSAGES_PERMISSIONS` |
| `notifications.enabled` | bool | `false` | `MESSAGES_NOTIFICATIONS` |
| `notifications.notification` | class-string | `NewMessageNotification` | — |
| `notifications.channels` | list | `['database']` | — |
| `preview.length` | int | `120` | `MESSAGES_PREVIEW_LENGTH` |
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

Broadcast a live typing indicator with `$thread->typing($user)` — it only broadcasts (never
persists) and respects `broadcasting.enabled`.

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

For host-app test suites, the `InteractsWithMessaging` trait adds fluent helpers and the
`MessageExpectations` matchers register Pest expectations:

```php
// tests/Pest.php
RoundlyConsulting\Messages\Testing\MessageExpectations::register();

// in a test
uses(RoundlyConsulting\Messages\Testing\InteractsWithMessaging::class);

$thread = $this->startConversation($alice, $bob);
$this->actingAsParticipant($alice)->sendMessageAs($thread, 'Hi');

expect($thread)
    ->toHaveSentMessage('Hi')
    ->toHaveParticipant($bob)
    ->toHaveUnread($bob)
    ->toHaveRole($alice, ParticipantRole::Owner);
```

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
