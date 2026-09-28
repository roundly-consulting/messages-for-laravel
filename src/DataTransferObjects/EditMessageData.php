<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Models\Message;

final readonly class EditMessageData
{
    /**
     * @param  Model|null  $actor  who edits it — must be the author; null skips the check
     */
    public function __construct(
        public Message $message,
        public string $body,
        public ?Model $actor = null,
    ) {}
}
