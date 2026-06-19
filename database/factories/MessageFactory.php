<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/** @extends Factory<Message> */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'thread_id' => ThreadFactory::new(),
            'sender_type' => null,
            'sender_id' => null,
            'message' => $this->faker->sentence(),
        ];
    }

    public function inThread(Thread $thread): self
    {
        return $this->state(fn (): array => ['thread_id' => $thread->getKey()]);
    }
}
