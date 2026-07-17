<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use RoundlyConsulting\Messages\Tests\TestCase;

/**
 * The suite's base case with ONLY the outbound `messages.key_type` set to `uuid` — the
 * inbound `messages.primary_key_type` is left at its `bigint` default.
 *
 * This is one half of the two-axes independence proof: the sender / participant morph
 * columns must become uuid while the package's own ids and internal foreign keys stay
 * bigint. The two key types are different axes and must not bleed into one another.
 */
abstract class MorphUuidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'messages.key_type' => 'uuid',
        ]);
    }
}
