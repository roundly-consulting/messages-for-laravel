<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use RoundlyConsulting\Messages\Tests\TestCase;

/**
 * The suite's base case with `messages.primary_key_type` set to `uuid` BEFORE the providers
 * boot and before the migrations run — the only window that matters, since the migrations
 * read the key type to pick the `id` column AND the four internal foreign keys, and the
 * models read it to decide whether to mint one.
 */
abstract class UuidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'messages.primary_key_type' => 'uuid',
        ]);
    }
}
