<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Enums;

use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Messages\MessagesManager;

/**
 * Every state-changing operation the {@see MessagesManager}
 * funnels through `perform()` — which is what `Messages::fake()` records and asserts on.
 */
enum MessagingOperation: string
{
    use Helpers;

    case Start = 'start';
    case Direct = 'direct';
    case Send = 'send';
    case MarkRead = 'mark_read';
    case Rename = 'rename';
    case Archive = 'archive';
    case Typing = 'typing';
    case AddParticipant = 'add_participant';
    case RemoveParticipant = 'remove_participant';
    case Leave = 'leave';
    case SetRole = 'set_role';
    case TransferOwnership = 'transfer_ownership';
    case Edit = 'edit';
    case Delete = 'delete';
    case Prune = 'prune';
}
