<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use RoundlyConsulting\Messages\Testing\InteractsWithMessaging;

/**
 * Exercises the {@see InteractsWithMessaging} trait so static analysis treats it as
 * used. The trait's behaviour is verified in tests/Testing/InteractsWithMessagingTest.
 */
class MessagingHarness
{
    use InteractsWithMessaging;
}
