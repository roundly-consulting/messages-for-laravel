# Changelog

All notable changes to `messages-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- A `{type}` placeholder for `messages.broadcasting.threads.per-participant-channel`: the
  participant's full morph type, lowercased with `\` as `.` (e.g.
  `messaging.participant.{type}.{id}` → `private-messaging.participant.app.models.admin.user.5`).
  `{name}` (the class basename) is unchanged, but without a morph map two models sharing a basename
  (`App\Models\User` and `App\Models\Admin\User`) share a `{name}` channel and hear each other's
  new threads — switch to `{type}` (and update `routes/channels.php`) or register a morph map.

### Fixed

- A thread's latest message (`latestMessage`, the inbox preview) no longer ends on an older
  message when two sends, or an unsend and a send, hit the same thread at once: the pointer is
  now recomputed under the thread's row lock (on MySQL the lookup is a locking read too), and
  sends to one thread are serialised. After a send, the thread you passed in now holds the
  pointer the package stored rather than assuming the message just sent is the newest.
- On a connection that fetches every column as a string (`PDO::ATTR_STRINGIFY_FETCHES`), a reply
  to a message of the same thread is no longer refused as a cross-thread reply, and a message
  holding its thread in memory keeps that thread's latest-message pointer current.
- A reply to a system message (a join, leave or rename notice) now quotes the notice as it reads
  ("Anna joined the conversation.") instead of its raw translation key, in `meta.quote.excerpt`
  and in `MessageResource`'s `reply_to.excerpt`.
- A reply to an unsent message of the same thread is still refused, but now with
  `MessageException::alreadyDeleted()` instead of the misleading "a reply must target a message in
  the same thread".
- `messages:prune` now refuses a `--days` value that is not a whole number of at least 1 and exits
  with an error before deleting anything. `--days=0` and `--days=-1` used to delete every message
  (attachment files included), and `--days=ten` / `--days=1.9` silently ran with the configured
  window / 1 day. Likewise `Messages::prune()` (and the `PruneMessages` action) now throws
  `InvalidArgumentException` for a window below one day instead of deleting everything.
- `messages:prune` now runs through `MessagesManager::prune()`, so `Messages::fake()` records it
  and `assertPruned()` sees a console or scheduled prune. A `--thread` id that names no thread now
  exits with an error.
- `Participant::hasUnread()` on a participation of a soft-deleted thread now returns `false`
  instead of crashing, and `Participant::markAsRead()` there throws
  `ParticipationException::threadMissing()` (English and Slovak) instead of a `TypeError`.
- An unsend and a leave are broadcast once (`messaging.message.unsent`,
  `messaging.participant.left`) instead of twice: a soft delete fires both the `trashed` and the
  `deleted` model event, and the `deleted` broadcast now goes out for a force delete only.
- `MessageResource`'s `edited_at` (and the published resource's) now reports real edits only. It
  used to be read off `updated_at`, so an unsent or restored message showed as edited and an edit
  within the second of the send did not. A change of the message body now records
  `meta.edited_at`, and `edited_at` reads that. Messages edited before this release carry no
  marker and report `edited_at` as `null`; a published resource needs the same `editedAt()` change
  (copy it from the package's `MessageResource`).
- Permission checks no longer trust a stale or trashed `participants` relation. On a thread
  instance whose participants were already loaded, a participant removed, demoted or no longer
  owner could still rename, archive or change roles, and a host loading the relation
  `withTrashed()` let someone who had left manage the thread. `Thread::participationOf()` now
  ignores trashed rows, and adding, removing, re-roling and transferring ownership drop the loaded
  relation.
- A group thread can no longer be left without an owner. The owner may still leave last, but the
  next participant to join (or be added) — on any group thread with no owner, including one started
  without participants — now becomes its owner instead of a member, so the thread stays manageable.
  Removing a participant now takes the same per-thread lock as adding one, so a join racing the
  owner's departure cannot end ownerless either.
- **Behaviour change:** a direct thread no longer takes new participants once it exists.
  `participants()->add()` on a DM — by a participant or a trusted caller without `by:`, and for
  someone who left as well — now throws `ParticipationException::directThreadIsClosed()` (English
  and Slovak). Any DM participant could add a third person, who then read the pair's whole history
  in a thread that still answered as their DM. Start a group thread to bring someone in; adding
  someone already in the DM still returns their row.
- **Behaviour change:** a direct thread made with the builder
  (`Messages::start()->direct()->withParticipants(...)`) of one or two participants now carries the
  pair key, like `Messages::direct()`'s. A second DM for a pair that has one is refused
  (`UniqueConstraintViolationException`) instead of becoming an unkeyed duplicate, more than two
  participants throw `ParticipationException::directThreadTakesTwo()` (English and Slovak), and a
  builder DM with no participants stays unkeyed. `Messages::direct()` now looks the DM up oldest
  first, so it always returns the same thread.

## 1.0.2 - 2026-10-04

### Fixed

- Join and leave system messages now show the participant's name (the `name` from
  `participateAs()`) instead of the literal `:participant` placeholder, in English and Slovak.
  A participant without a name reads as "An unnamed participant"; messages already stored render
  correctly too.
- Permission refusals (`UnauthorizedMessagingAction`) now name the refused action and the required
  role in the current locale, so a Slovak message no longer contains English phrases such as
  "transfer ownership". They come from the new `messages::messages.permissions.actions.*` and
  `messages::messages.permissions.roles.*` lines; a plain phrase passed to
  `UnauthorizedMessagingAction::for()` / `requiresRole()` is still used as it is.

## 1.0.1 - 2026-10-04

### Changed

- Maintenance: `composer.json` `homepage` and `support.docs` now point to the documentation site.

### Fixed

- Slovak (`sk`) translations now ship alongside English for every language file.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Direct messages and group conversations between any Eloquent models via the `HasMessaging`
  trait: `conversationWith()`, `startConversationWith()`, `sendMessageTo()`.
- A `Messages` facade over an injectable `MessagesManager` that reaches every action:
  `start()`, `to()`, `direct()`, `send()`, `markRead()`, `unreadCount()`, `inboxFor()`,
  `threads()`, `prune()`; a `thread($t)` handle (`rename()`, `archive()`, `markRead()`,
  `typing()`, `messages()`, `message()`) with `participants()` (`add()`, `remove()`,
  `leave()`, `setRole()`, `transferOwnership()`); and a `message($m)` handle (`edit()`,
  `delete()`). Scoped handles refuse a message or participant row of another thread.
- Owner, admin and member roles in group threads, with ownership transfer, renaming and archiving.
  Only the owner changes roles, and ownership moves only through `transferOwnership()`. Only a
  message's author may edit it; owners and admins may delete others' messages. Every actor-checked
  operation requires the actor to be a participant, with roles on or off.
- Find-or-add participants: adding someone already in a thread returns their existing row, and a
  self-join (`joinThread()`) is allowed only on threads open to everyone.
- Read receipts and unread counts per thread or overall.
- Replies that keep a snapshot of the quoted message, plus editing, unsending and translatable
  system messages.
- Private file attachments backed by the media library, reachable only through signed,
  short-lived URLs.
- `Messages::inboxFor()`: a paginated inbox with the latest message and unread counts, without
  N+1 queries.
- Plain Laravel events for every change (`MessageSent`, `ThreadRead`, `ParticipantJoined`, …),
  optional real-time broadcasting and live typing indicators.
- Opt-in Laravel notifications for new messages, JSON API resources and query scopes.
- `php artisan messages:prune` to delete old messages.
- `Messages::fake()`: a still-performing `MessagesManager` subtype that records every
  operation — through the facade, an injected manager, the builders, the handles and the model
  traits — with `assertThreadCreated/Sent/ThreadRenamed/ThreadArchived/MarkedRead/Typing/
  ParticipantAdded/ParticipantRemoved/RoleChanged/OwnershipTransferred/Edited/Deleted/Pruned`
  and an `assertNothing…` for each.
- Model factories and Pest expectations for testing your app.
