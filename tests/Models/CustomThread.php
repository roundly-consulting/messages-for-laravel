<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use RoundlyConsulting\Messages\Models\Thread;

/**
 * A host subclass of the packaged thread model, configured through
 * `messages.models.thread`. Its class name deliberately differs from the
 * packaged one so a relation that derived its foreign key from the parent
 * class (`custom_thread_id`) instead of naming it (`thread_id`) breaks loudly.
 */
final class CustomThread extends Thread {}
