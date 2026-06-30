<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Enums;

use RoundlyConsulting\Enums\Helpers;

enum ParticipantRole: string
{
    use Helpers;

    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** Roles able to manage participants, rename, archive, and moderate others' messages. */
    public function canManage(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /** Only the owner may transfer ownership or delete the thread. */
    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }
}
