<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use RoundlyConsulting\Messages\Tests\TestCase;

/**
 * The suite's base case with `messages.primary_key_type` set to `ulid` BEFORE the providers
 * boot and before the migrations run.
 *
 * @see UuidKeyTestCase
 */
abstract class UlidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'messages.primary_key_type' => 'ulid',
        ]);
    }
}
