<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Tests\Fixtures\LatestMessageVectors;

/**
 * The frozen answers on the default (bigint) key. See {@see LatestMessageVectors} for why
 * these are written down before the relation changes rather than after.
 *
 * The uuid and ulid legs run the same table in tests/KeyTypes/.
 */
afterEach(fn () => Carbon::setTestNow());

it('resolves the latest message', function (callable $scenario): void {
    [$thread, $expected] = $scenario();

    LatestMessageVectors::assertScenario($thread, $expected);
})->with(LatestMessageVectors::scenarios());
