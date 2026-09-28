<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Facades;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Handles\MessageHandle;
use RoundlyConsulting\Messages\Handles\ThreadHandle;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Testing\MessagesFake;

/**
 * @method static PendingThread start(?string $name = null)
 * @method static PendingMessage to(Thread $thread)
 * @method static Thread direct(Model $first, Model $second)
 * @method static Message send(Thread $thread, ?Model $sender, string $body)
 * @method static Participant markRead(Thread $thread, Model $participant)
 * @method static int unreadCount(Model $participant, ?Thread $thread = null)
 * @method static LengthAwarePaginator<int, Thread> inboxFor(Model $participant, int $perPage = 15, int $page = 1, string $pageName = 'page')
 * @method static LengthAwarePaginator<int, Thread> threads(?Model $for = null, int $perPage = 15, int $page = 1, string $pageName = 'page')
 * @method static ThreadHandle thread(Thread $thread)
 * @method static MessageHandle message(Message $message)
 * @method static int prune(?int $days = null, ?Thread $thread = null)
 * @method static list<MessagingCall> recorded(?MessagingOperation $operation = null)
 * @method static void assertThreadCreated(?string $name = null)
 * @method static void assertNothingCreated()
 * @method static void assertSent(?string $body = null, ?Thread $to = null)
 * @method static void assertSentCount(int $count)
 * @method static void assertNothingSent()
 * @method static void assertThreadRenamed(Thread $thread, ?string $to = null)
 * @method static void assertNothingRenamed()
 * @method static void assertThreadArchived(Thread $thread)
 * @method static void assertNothingArchived()
 * @method static void assertMarkedRead(Thread $thread, ?Model $by = null)
 * @method static void assertNothingMarkedRead()
 * @method static void assertTyping(Thread $thread, ?Model $participant = null)
 * @method static void assertNothingTyping()
 * @method static void assertParticipantAdded(Thread $thread, ?Model $participant = null)
 * @method static void assertNothingAdded()
 * @method static void assertParticipantRemoved(Thread $thread, ?Model $participant = null)
 * @method static void assertNothingRemoved()
 * @method static void assertRoleChanged(Thread $thread, ?Model $participant = null, ?ParticipantRole $role = null)
 * @method static void assertNothingRoleChanged()
 * @method static void assertOwnershipTransferred(Thread $thread, ?Model $to = null)
 * @method static void assertNothingTransferred()
 * @method static void assertEdited(Message $message, ?string $body = null)
 * @method static void assertNothingEdited()
 * @method static void assertDeleted(Message $message)
 * @method static void assertNothingDeleted()
 * @method static void assertPruned(?int $days = null)
 * @method static void assertNothingPruned()
 *
 * @see MessagesManager
 * @see MessagesFake
 */
final class Messages extends Facade
{
    /**
     * Swap in a recording fake. Operations still run; the fake records each one — through
     * the facade, injected managers, builders, handles and the model traits — for assertions.
     */
    public static function fake(): MessagesFake
    {
        $fake = self::getFacadeApplication()->make(MessagesFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MessagesManager::class;
    }
}
