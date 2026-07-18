<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/** A host subclass of the packaged message model (`messages.models.message`). */
final class CustomMessage extends Message
{
    /**
     * Counting `created` events on this exact class is the independent oracle a swap
     * really took effect. Without it `toHonourModelSwap` silently downgrades to an
     * `instanceof` check, which a row created as the *packaged* class can still pass.
     */
    use CountsCreations;
}
