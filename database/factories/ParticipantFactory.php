<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

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
            'read_at' => null,
        ];
    }

    public function inThread(Thread $thread): self
    {
        return $this->state(fn (): array => ['thread_id' => $thread->getKey()]);
    }
}
