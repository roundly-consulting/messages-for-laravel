<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * Registers Pest custom expectations for messaging. Call once from the host app's
 * tests/Pest.php:
 *
 *     RoundlyConsulting\Messages\Testing\MessageExpectations::register();
 *
 * Expectations resolve Pest's expect() at call time, so the package keeps no runtime
 * dependency on Pest.
 */
final class MessageExpectations
{
    public static function register(): void
    {
        if (! function_exists('expect')) {
            return;
        }

        expect()->extend('toHaveSentMessage', function (?string $body = null) {
            /** @var Thread $thread */
            $thread = $this->value;

            $query = $thread->messages();

            if ($body !== null) {
                $query->where('message', $body);
            }

            Assert::assertTrue(
                $query->exists(),
                $body === null
                    ? 'Expected the thread to have at least one message.'
                    : "Expected the thread to contain a message with body [{$body}].",
            );

            return $this;
        });

        expect()->extend('toHaveParticipant', function (Model $participant) {
            /** @var Thread $thread */
            $thread = $this->value;

            Assert::assertTrue(
                $thread->participants()->whereMorphedTo('participant', $participant)->exists(),
                'Expected the model to be a participant of the thread.',
            );

            return $this;
        });

        expect()->extend('toHaveUnread', function (Model $participant) {
            /** @var Thread $thread */
            $thread = $this->value;

            Assert::assertGreaterThan(
                0,
                $thread->unreadCountFor($participant),
                'Expected the thread to have unread messages for the participant.',
            );

            return $this;
        });

        expect()->extend('toHaveRole', function (Model $participant, ParticipantRole $role) {
            /** @var Thread $thread */
            $thread = $this->value;

            Assert::assertSame(
                $role,
                $thread->roleOf($participant),
                "Expected the participant to hold the [{$role->value}] role.",
            );

            return $this;
        });

        expect()->extend('toBeReplyTo', function (Message $parent) {
            /** @var Message $message */
            $message = $this->value;

            Assert::assertSame(
                (string) $parent->getKey(),
                (string) $message->parent_message_id,
                'Expected the message to be a reply to the given parent.',
            );

            return $this;
        });
    }
}
