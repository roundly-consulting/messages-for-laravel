<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * Records writes made through the Messages facade so tests can assert on them
 * without disabling the real underlying behaviour.
 */
final class MessagesFake extends MessagesManager
{
    /** @var list<Message> */
    public array $sent = [];

    public function __construct(
        private readonly MessagesManager $manager,
    ) {}

    public function thread(?string $name = null): PendingThread
    {
        return $this->manager->thread($name);
    }

    public function to(Thread $thread): PendingMessage
    {
        return $this->manager->to($thread);
    }

    public function direct(Model $first, Model $second): Thread
    {
        return $this->manager->direct($first, $second);
    }

    public function send(Thread $thread, ?Model $sender, string $body): Message
    {
        $message = $this->manager->send($thread, $sender, $body);

        $this->sent[] = $message;

        return $message;
    }

    public function markRead(Thread $thread, Model $participant): Participant
    {
        return $this->manager->markRead($thread, $participant);
    }

    public function unreadCount(Model $participant, ?Thread $thread = null): int
    {
        return $this->manager->unreadCount($participant, $thread);
    }

    public function assertSent(?string $body = null): void
    {
        if ($body === null) {
            Assert::assertNotEmpty($this->sent, 'Expected a message to be sent, but none were.');

            return;
        }

        $found = array_filter($this->sent, fn (Message $message): bool => $message->message === $body);

        Assert::assertNotEmpty($found, "Expected a message with body [{$body}] to be sent.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, 'Expected no messages to be sent.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent);
    }
}
