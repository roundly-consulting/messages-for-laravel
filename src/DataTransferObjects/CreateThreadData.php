<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

final readonly class CreateThreadData
{
    /**
     * @param  list<Model>  $participants
     */
    public function __construct(
        public ?string $name = null,
        public ?bool $isPublic = null,
        public ?bool $everyoneCanJoin = null,
        public bool $isDirect = false,
        public array $participants = [],
    ) {}
}
