<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessageModel;

/** @extends Factory<Participant> */
final class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    public function definition(): array
    {
        return [
            'thread_id' => ThreadFactory::new(),
            'participant_type' => 'user',
            'participant_id' => $this->faker->randomNumber(),
            'role' => null,
            'read_at' => null,
            'last_read_message_id' => null,
        ];
    }

    public function role(ParticipantRole $role): self
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function owner(): self
    {
        return $this->role(ParticipantRole::Owner);
    }

    public function inThread(Thread $thread): self
    {
        return $this->state(fn (): array => ['thread_id' => $thread->getKey()]);
    }

    /**
     * Read up to the thread's newest message, as `Messages::markRead()` leaves it: read state is
     * computed from the `last_read_message_id` pointer, `read_at` only records when.
     */
    public function read(): self
    {
        return $this->state(fn (): array => [
            'read_at' => now(),
            'last_read_message_id' => static fn (array $attributes): int|string|null => MessageModel::class()::newestMessageIdIn($attributes['thread_id']),
        ]);
    }

    public function unread(): self
    {
        return $this->state(fn (): array => ['read_at' => null, 'last_read_message_id' => null]);
    }
}
