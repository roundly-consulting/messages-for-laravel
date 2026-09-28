<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\CustomMessage;
use RoundlyConsulting\Messages\Tests\Models\CustomParticipant;
use RoundlyConsulting\Messages\Tests\Models\CustomThread;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * The model-swap proofs (S) for all three `messages.models.*` seams, driven through the REAL
 * flows rather than through the resolver.
 *
 * The bugs this class of test exists for are the retrofit's single biggest class:
 *
 *  - a runtime `config()->set()` leaves every listener the provider hung at boot on the
 *    packaged class (media #28);
 *  - `instanceof` passes for a row created as the *packaged* class — which never fires the
 *    host's model events (permissions #31). Only a `created` event counted on the subclass
 *    itself proves the row was made as the host's model, which is why each fixture uses
 *    {@see CountsCreations}: without it `toHonourModelSwap` silently downgrades;
 *  - a hard-coded call site sitting beside an honoured config (shops #3, media #28) — which
 *    is why the exercises below go through the service and its repositories, never through
 *    the seam. Reading back with `MessageModel::class()::query()` would only prove the
 *    resolver resolves; reading back through a package flow proves the *package* hydrates
 *    the host's class.
 *
 * The swap is applied before boot by {@see SwappedModelsTestCase}, which this directory is
 * bound to — Pest binds a test case per directory, not per file.
 */
it('honours a host thread model through the real start-thread flow', function (): void {
    expect('messages.models.thread')->toHonourModelSwap(CustomThread::class, function (): array {
        $thread = Messages::start('Swapped crew')->create();

        return [
            $thread,
            // The inbox listing hydrates through the package's own query, not the seam.
            ...Messages::threads()->getCollection()->all(),
        ];
    });
});

it('honours a host message model through the real send flow', function (): void {
    expect('messages.models.message')->toHonourModelSwap(CustomMessage::class, function (): array {
        $sender = User::create();
        $thread = Messages::start('Swapped send')->withParticipant($sender)->create();

        $message = Messages::send($thread, $sender, 'sent through the swapped model');

        return [
            $message,
            // latestMessage() is the relation this row also fixed; it must hydrate the
            // host's class, not the packaged one.
            $thread->latestMessage()->first(),
            ...Messages::thread($thread)->messages()->getCollection()->all(),
        ];
    });
});

it('honours a host participant model when a thread is joined', function (): void {
    expect('messages.models.participant')->toHonourModelSwap(CustomParticipant::class, function (): array {
        $owner = User::create();
        $thread = Messages::start('Swapped join')->create();

        $participant = Messages::thread($thread)->participants()->add($owner);

        return [
            $participant,
            // The relation hydrates through the seam too, not just the write.
            ...$thread->participants()->get()->all(),
        ];
    });
});

// The structural half of each seam — the models are non-final, and every `messages.models.*`
// key really defaults to the packaged model — is pinned once in tests/ArchTest.php by
// `ArchPresets::swappableModelsAreNotFinal()`. It deliberately does NOT live here: that
// preset asserts the config *default*, which this directory has swapped away.
