<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * One operation made through the manager: what it was, what it touched and — once it has
 * run — what it returned. `Messages::fake()` keeps a list of these.
 *
 * - `participant` — the model the operation is about: the sender of a message, the
 *   participant added/removed/re-roled/marking read/typing, the new owner of a transfer, the
 *   second side of a direct thread.
 * - `actor` — who performed it (`by:`), the outgoing owner of a transfer, the first side of a
 *   direct thread.
 * - `text` — a thread name or a message body.
 */
final readonly class MessagingCall
{
    public function __construct(
        public MessagingOperation $operation,
        public ?Thread $thread = null,
        public ?Message $message = null,
        public ?Model $participant = null,
        public ?Model $actor = null,
        public ?string $text = null,
        public ?ParticipantRole $role = null,
        public ?int $days = null,
        public mixed $result = null,
    ) {}

    public function withResult(mixed $result): self
    {
        return new self(
            operation: $this->operation,
            thread: $this->thread,
            message: $this->message,
            participant: $this->participant,
            actor: $this->actor,
            text: $this->text,
            role: $this->role,
            days: $this->days,
            result: $result,
        );
    }
}
