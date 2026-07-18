<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/** A host subclass of the packaged participant model (`messages.models.participant`). */
final class CustomParticipant extends Participant
{
    /**
     * Counting `created` events on this exact class is the independent oracle a swap
     * really took effect. Without it `toHonourModelSwap` silently downgrades to an
     * `instanceof` check, which a row created as the *packaged* class can still pass.
     */
    use CountsCreations;
}
