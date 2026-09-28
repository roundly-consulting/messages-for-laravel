<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Testing\MessageExpectations;
use RoundlyConsulting\Messages\Tests\Fixtures\MorphUuidKeyTestCase;
use RoundlyConsulting\Messages\Tests\Fixtures\SwappedModelsTestCase;
use RoundlyConsulting\Messages\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Messages\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Messages\Tests\TestCase;

/**
 * Explicit paths, not `->in(__DIR__)`: the ModelSwap directory below needs a different base
 * case, and a blanket bind claims it first — Pest binds a test case per directory, not per
 * file, and errors out ("the folder already uses the test case") rather than picking the
 * more specific one.
 *
 * The root-level test files are named individually because `->in()` accepts a file path as
 * well as a directory. ArchTest.php is listed for a second reason: it needs the app booted
 * (`swappableModelsAreNotFinal` reads the `messages.models.*` config defaults), and an arch
 * file is not automatically test-cased.
 */
uses(TestCase::class)->in(
    __DIR__.'/Actions',
    __DIR__.'/ArchTest.php',
    __DIR__.'/Builders',
    __DIR__.'/Commands',
    __DIR__.'/Concerns',
    __DIR__.'/DataTransferObjects',
    __DIR__.'/Enums',
    __DIR__.'/Events',
    __DIR__.'/Facades',
    __DIR__.'/Feature',
    __DIR__.'/Http',
    // The bigint leg rides the default base case precisely because it must prove the
    // *unconfigured* install is correct.
    __DIR__.'/KeyTypes/BigIntKeyTest.php',
    __DIR__.'/MessageTest.php',
    __DIR__.'/Models',
    __DIR__.'/Notifications',
    __DIR__.'/ParticipantTest.php',
    __DIR__.'/Support',
    __DIR__.'/Testing',
    __DIR__.'/ThreadTest.php',
);

/**
 * The model-swap proofs need every `messages.models.*` key pointed at a host subclass BEFORE
 * the providers boot, so they run on their own base case in their own directory.
 */
uses(SwappedModelsTestCase::class)->in(__DIR__.'/ModelSwap');

/**
 * The key-type seam is fixed at migrate time — the migrations read it to pick the `id` column
 * AND the four internal foreign keys — so each non-default leg needs
 * `messages.primary_key_type` set before the providers boot. A base case per key type is the
 * only way to reach that window.
 */
uses(UuidKeyTestCase::class)->in(__DIR__.'/KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in(__DIR__.'/KeyTypes/UlidKeyTest.php');

/**
 * The outbound morph axis. This leg sets ONLY `messages.key_type` (leaving the inbound
 * `primary_key_type` at its bigint default) to prove the two key types are independent.
 */
uses(MorphUuidKeyTestCase::class)->in(__DIR__.'/KeyTypes/MorphKeyTypeTest.php');

MessageExpectations::register();
