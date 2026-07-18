<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Notifications\NewMessageNotification;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * The arch presets. Messages shipped **no arch file at all** before this row — the same
 * gap that let jwt ship `final` on a config-swappable model (the fleet's 7×-shipped fatal).
 */
ArchPresets::strictTypes('RoundlyConsulting\Messages');

/**
 * Exempt from finality, each deliberately:
 *  - Thread / Message / Participant — `messages.models.*` invites a host to subclass each
 *    one; `final` here is a PHP fatal the moment a host uses the documented seam. Pinned
 *    positively below instead.
 *  - NewMessageNotification — `messages.notifications.notification` documents overriding it
 *    to change channels, content or queueing. It is *not* a model (it extends
 *    Notification), so it is exempt here but deliberately absent from the swap map below.
 *  - MessagesManager — the package's own FakeMessagesManager extends it, which is how
 *    `Messages::fake()` works. `final` would break a feature this package ships.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Messages', [
    Thread::class,
    Message::class,
    Participant::class,
    NewMessageNotification::class,
    MessagesManager::class,
]);

/**
 * The counter-weight, and the fleet's 7×-shipped fatal. Also pins that each
 * `messages.models.*` key really defaults to the packaged model, so the seam cannot rot in
 * the other direction.
 *
 * `messages.notifications.notification` is deliberately NOT here: it resolves a Notification,
 * not an Eloquent model, so it is outside what this preset means by a swappable model. The
 * row spec counted it and scored `Swap? 4`; the real count is 3.
 */
ArchPresets::swappableModelsAreNotFinal([
    Thread::class => 'messages.models.thread',
    Message::class => 'messages.models.message',
    Participant::class => 'messages.models.participant',
]);

/**
 * Messages mints no keys or tokens of its own — uuid7 primary keys come from Laravel's
 * HasUuids, and the signed attachment URLs are Laravel's signer. This pins that a
 * hand-rolled scheme never lands here.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Messages');

/**
 * All three `messages.models.*` keys resolve through the Support seam
 * (`ThreadModel`/`MessageModel`/`ParticipantModel`). Adopted on the pre-classified rule
 * (Swap? > 0): messages has exactly the shape the preset targets — real Eloquent models
 * behind `*_model`-shaped keys, every call site going through the seam.
 *
 * The keys are declared explicitly: they are nested under `models.` rather than named
 * `*_model`, which the preset's shape inference does not see.
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support', [
    'messages.models.thread',
    'messages.models.message',
    'messages.models.participant',
]);

/**
 * The Dependency Policy as a test. No `alsoAllow`: messages' `require` ships only
 * php/illuminate/roundly, and the workflow installs test tooling with `--dev`. If this goes
 * red the graph is wrong — never widen the allow-list to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();
