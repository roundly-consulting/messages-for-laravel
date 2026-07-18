<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use RoundlyConsulting\Messages\Tests\Models\CustomMessage;
use RoundlyConsulting\Messages\Tests\Models\CustomParticipant;
use RoundlyConsulting\Messages\Tests\Models\CustomThread;
use RoundlyConsulting\Messages\Tests\TestCase;

/**
 * The suite's base case with all three `messages.models.*` keys pointed at host subclasses
 * BEFORE the providers boot.
 *
 * Boot order is the whole point. The provider hangs event listeners (MessageSent ->
 * NotifyParticipantsOfNewMessage / WarmMessageMediaVariants) and the models' own
 * BroadcastsEvents hooks on whatever these keys name at boot. A `config()->set()` inside a
 * test body reads back correctly but leaves every listener on the packaged class — precisely
 * the shape that let media #28 ship, and precisely what the existing
 * `tests/Support/ConfiguredModelsTest.php` cannot see, because it swaps in the test body.
 * That file tests the *resolver*, which is a different (and legitimate) question; this
 * directory tests the *swap*.
 *
 * Note the `array_merge(parent::configBeforeBoot(), …)`: dropping it would silently discard
 * the base case's app.key and media wiring — the same decapitation an un-parented
 * `defineEnvironment()` override causes one level up.
 *
 * @see TestCase
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'messages.models.thread' => CustomThread::class,
            'messages.models.message' => CustomMessage::class,
            'messages.models.participant' => CustomParticipant::class,
        ]);
    }
}
