# Changelog

All notable changes to `messages-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
