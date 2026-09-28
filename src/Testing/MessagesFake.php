<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * A recording, still-performing {@see MessagesManager}, swapped in by `Messages::fake()`.
 *
 * Every operation still runs against the database, fires its events and broadcasts; the fake
 * records each one that succeeds — whether it came through the facade, an injected manager,
 * a builder (`start()->create()`, `to()->send()`), a handle (`thread()`, `participants()`,
 * `message()`) or a model (`$user->sendMessageTo()`, `$thread->markReadFor()`) — so a test
 * can assert on what happened.
 *
 * Work an action does on its own behalf is not recorded: the participants `start()` adds, the
 * system messages a rename writes, the thread a first `direct()` creates internally.
 */
final class MessagesFake extends MessagesManager
{
    /** @var list<MessagingCall> */
    private array $recorded = [];

    /**
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @return TResult
     */
    public function perform(MessagingCall $call, string $action, Closure $execute): mixed
    {
        $result = parent::perform($call, $action, $execute);

        $this->recorded[] = $call->withResult($result);

        return $result;
    }

    /**
     * Every recorded call, optionally of one operation only, in call order.
     *
     * @return list<MessagingCall>
     */
    public function recorded(?MessagingOperation $operation = null): array
    {
        if ($operation === null) {
            return $this->recorded;
        }

        return array_values(array_filter(
            $this->recorded,
            static fn (MessagingCall $call): bool => $call->operation === $operation,
        ));
    }

    /**
     * A thread was created — by `start()->create()`, or by a `direct()` that found none.
     */
    public function assertThreadCreated(?string $name = null): void
    {
        $created = array_filter(
            $this->created(),
            static fn (Thread $thread): bool => $name === null || $thread->name === $name,
        );

        Assert::assertNotEmpty($created, $name === null
            ? 'Expected a thread to be created, but none was.'
            : "Expected a thread named [{$name}] to be created, but none was.");
    }

    public function assertNothingCreated(): void
    {
        Assert::assertEmpty($this->created(), sprintf('Expected no thread to be created, but %d were.', count($this->created())));
    }

    public function assertSent(?string $body = null, ?Thread $to = null): void
    {
        $this->assertRecorded(
            MessagingOperation::Send,
            static fn (MessagingCall $call): bool => ($body === null || $call->text === $body)
                && ($to === null || self::same($call->thread, $to)),
            $body === null ? 'Expected a message to be sent, but none were.' : "Expected a message with body [{$body}] to be sent.",
        );
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded(MessagingOperation::Send), "Expected {$count} message(s) to be sent.");
    }

    public function assertNothingSent(): void
    {
        $this->assertNone(MessagingOperation::Send, 'Expected no messages to be sent, but %d were.');
    }

    public function assertThreadRenamed(Thread $thread, ?string $to = null): void
    {
        $this->assertRecorded(
            MessagingOperation::Rename,
            static fn (MessagingCall $call): bool => self::same($call->thread, $thread) && ($to === null || $call->text === $to),
            $to === null ? 'Expected the thread to be renamed, but it was not.' : "Expected the thread to be renamed to [{$to}], but it was not.",
        );
    }

    public function assertNothingRenamed(): void
    {
        $this->assertNone(MessagingOperation::Rename, 'Expected no thread to be renamed, but %d were.');
    }

    public function assertThreadArchived(Thread $thread): void
    {
        $this->assertRecorded(
            MessagingOperation::Archive,
            static fn (MessagingCall $call): bool => self::same($call->thread, $thread),
            'Expected the thread to be archived, but it was not.',
        );
    }

    public function assertNothingArchived(): void
    {
        $this->assertNone(MessagingOperation::Archive, 'Expected no thread to be archived, but %d were.');
    }

    public function assertMarkedRead(Thread $thread, ?Model $by = null): void
    {
        $this->assertRecorded(
            MessagingOperation::MarkRead,
            static fn (MessagingCall $call): bool => self::same($call->thread, $thread) && ($by === null || self::same($call->participant, $by)),
            'Expected the thread to be marked read, but it was not.',
        );
    }

    public function assertNothingMarkedRead(): void
    {
        $this->assertNone(MessagingOperation::MarkRead, 'Expected nothing to be marked read, but %d were.');
    }

    public function assertTyping(Thread $thread, ?Model $participant = null): void
    {
        $this->assertRecorded(
            MessagingOperation::Typing,
            static fn (MessagingCall $call): bool => self::same($call->thread, $thread) && ($participant === null || self::same($call->participant, $participant)),
            'Expected a typing signal on the thread, but none was sent.',
        );
    }

    public function assertNothingTyping(): void
    {
        $this->assertNone(MessagingOperation::Typing, 'Expected no typing signal, but %d were sent.');
    }

    /**
     * A participant was added to the thread. Adding someone who was already in it returns their
     * existing row and does not count.
     */
    public function assertParticipantAdded(Thread $thread, ?Model $participant = null): void
    {
        Assert::assertNotEmpty(
            array_filter($this->added(), static fn (MessagingCall $call): bool => self::same($call->thread, $thread)
                && ($participant === null || self::same($call->participant, $participant))),
            'Expected a participant to be added to the thread, but none was.',
        );
    }

    public function assertNothingAdded(): void
    {
        $count = count($this->added());

        Assert::assertSame(0, $count, sprintf('Expected no participant to be added, but %d were.', $count));
    }

    /**
     * A participant was removed from the thread — by someone else or by leaving.
     */
    public function assertParticipantRemoved(Thread $thread, ?Model $participant = null): void
    {
        $removed = [...$this->recorded(MessagingOperation::RemoveParticipant), ...$this->recorded(MessagingOperation::Leave)];

        Assert::assertNotEmpty(
            array_filter($removed, static fn (MessagingCall $call): bool => self::same($call->thread, $thread)
                && ($participant === null || self::same($call->participant, $participant))),
            'Expected a participant to be removed from the thread, but none was.',
        );
    }

    public function assertNothingRemoved(): void
    {
        $count = count($this->recorded(MessagingOperation::RemoveParticipant)) + count($this->recorded(MessagingOperation::Leave));

        Assert::assertSame(0, $count, sprintf('Expected no participant to be removed, but %d were.', $count));
    }

    public function assertRoleChanged(Thread $thread, ?Model $participant = null, ?ParticipantRole $role = null): void
    {
        $this->assertRecorded(
            MessagingOperation::SetRole,
            static fn (MessagingCall $call): bool => self::same($call->thread, $thread)
                && ($participant === null || self::same($call->participant, $participant))
                && ($role === null || $call->role === $role),
            'Expected a participant role to change, but none did.',
        );
    }

    public function assertNothingRoleChanged(): void
    {
        $this->assertNone(MessagingOperation::SetRole, 'Expected no role to change, but %d did.');
    }

    public function assertOwnershipTransferred(Thread $thread, ?Model $to = null): void
    {
        $this->assertRecorded(
            MessagingOperation::TransferOwnership,
            static fn (MessagingCall $call): bool => self::same($call->thread, $thread) && ($to === null || self::same($call->participant, $to)),
            'Expected ownership of the thread to be transferred, but it was not.',
        );
    }

    public function assertNothingTransferred(): void
    {
        $this->assertNone(MessagingOperation::TransferOwnership, 'Expected no ownership transfer, but %d happened.');
    }

    public function assertEdited(Message $message, ?string $body = null): void
    {
        $this->assertRecorded(
            MessagingOperation::Edit,
            static fn (MessagingCall $call): bool => self::same($call->message, $message) && ($body === null || $call->text === $body),
            'Expected the message to be edited, but it was not.',
        );
    }

    public function assertNothingEdited(): void
    {
        $this->assertNone(MessagingOperation::Edit, 'Expected no message to be edited, but %d were.');
    }

    public function assertDeleted(Message $message): void
    {
        $this->assertRecorded(
            MessagingOperation::Delete,
            static fn (MessagingCall $call): bool => self::same($call->message, $message),
            'Expected the message to be deleted, but it was not.',
        );
    }

    public function assertNothingDeleted(): void
    {
        $this->assertNone(MessagingOperation::Delete, 'Expected no message to be deleted, but %d were.');
    }

    public function assertPruned(?int $days = null): void
    {
        $this->assertRecorded(
            MessagingOperation::Prune,
            static fn (MessagingCall $call): bool => $days === null || $call->days === $days,
            $days === null ? 'Expected messages to be pruned, but they were not.' : "Expected messages older than {$days} day(s) to be pruned.",
        );
    }

    public function assertNothingPruned(): void
    {
        $this->assertNone(MessagingOperation::Prune, 'Expected nothing to be pruned, but %d prune(s) ran.');
    }

    /**
     * Threads created through the manager: every `start()`, and the `direct()` calls that
     * had to create theirs.
     *
     * @return list<Thread>
     */
    private function created(): array
    {
        $threads = [];

        foreach ($this->recorded as $call) {
            $created = $call->operation === MessagingOperation::Start
                || ($call->operation === MessagingOperation::Direct && $call->result instanceof Thread && $call->result->wasRecentlyCreated);

            if ($created && $call->result instanceof Thread) {
                $threads[] = $call->result;
            }
        }

        return $threads;
    }

    /**
     * Adds that really added someone — not the ones that found an existing participant.
     *
     * @return list<MessagingCall>
     */
    private function added(): array
    {
        return array_values(array_filter(
            $this->recorded(MessagingOperation::AddParticipant),
            static fn (MessagingCall $call): bool => $call->result instanceof Participant && $call->result->wasRecentlyCreated,
        ));
    }

    /**
     * @param  Closure(MessagingCall): bool  $matches
     */
    private function assertRecorded(MessagingOperation $operation, Closure $matches, string $message): void
    {
        Assert::assertNotEmpty(array_filter($this->recorded($operation), $matches), $message);
    }

    private function assertNone(MessagingOperation $operation, string $message): void
    {
        $count = count($this->recorded($operation));

        Assert::assertSame(0, $count, sprintf($message, $count));
    }

    private static function same(?Model $recorded, Model $expected): bool
    {
        return $recorded !== null && $recorded->is($expected);
    }
}
