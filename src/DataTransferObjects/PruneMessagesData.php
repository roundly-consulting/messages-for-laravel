<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

final readonly class PruneMessagesData
{
    public function __construct(
        public int $days,
        public ?string $threadId = null,
    ) {}
}
