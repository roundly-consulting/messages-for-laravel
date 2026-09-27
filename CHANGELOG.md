# Changelog

All notable changes to `messages-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Direct messages and group conversations between any Eloquent models via the `HasMessaging`
  trait: `conversationWith()`, `startConversationWith()`, `sendMessageTo()`.
- A `Messages` facade for threads, sending, read state and the inbox, backed by
  container-resolvable actions and DTOs.
- Owner, admin and member roles in group threads, with ownership transfer, renaming and archiving.
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
- `Messages::fake()`, model factories and Pest expectations for testing your app.
