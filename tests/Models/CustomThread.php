<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass of the packaged thread model, configured through
 * `messages.models.thread`. Its class name deliberately differs from the
 * packaged one so a relation that derived its foreign key from the parent
 * class (`custom_thread_id`) instead of naming it (`thread_id`) breaks loudly.
 */
final class CustomThread extends Thread
{
    /**
     * Counting `created` events on this exact class is the independent oracle a swap
     * really took effect. Without it `toHonourModelSwap` silently downgrades to an
     * `instanceof` check, which a row created as the *packaged* class can still pass.
     */
    use CountsCreations;
}
