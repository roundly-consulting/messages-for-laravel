<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Models\Thread;

final readonly class CreateThreadData
{
    /**
     * @param  list<Model>  $participants
     * @param  string|null  $directKey  The pair key of a direct thread ({@see Thread::directKeyFor()}),
     *                                  unique among threads: the database refuses a second
     *                                  thread with the same key. Set by
     *                                  {@see FindOrCreateDirectThread}; when null, a direct thread
     *                                  of one or two participants is keyed from them. Ignored on
     *                                  group threads.
     */
    public function __construct(
        public ?string $name = null,
        public ?bool $isPublic = null,
        public ?bool $everyoneCanJoin = null,
        public bool $isDirect = false,
        public array $participants = [],
        public ?string $directKey = null,
    ) {}
}
