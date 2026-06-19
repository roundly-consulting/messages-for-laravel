<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use RoundlyConsulting\Messages\Models\Message;

final readonly class EditMessageData
{
    public function __construct(
        public Message $message,
        public string $body,
    ) {}
}
