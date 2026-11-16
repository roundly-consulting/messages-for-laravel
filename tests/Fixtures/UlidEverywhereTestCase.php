<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use RoundlyConsulting\Messages\Tests\TestCase;

/**
 * A host that keys everything with ULIDs: the package's own tables
 * (`messages.primary_key_type`) AND the models it points at — participants, senders and the
 * thread context (`messages.key_type`) — set before the providers boot and the migrations run.
 */
abstract class UlidEverywhereTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'messages.primary_key_type' => 'ulid',
            'messages.key_type' => 'ulid',
        ]);
    }
}
