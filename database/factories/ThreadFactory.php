<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Messages\Models\Thread;

/** @extends Factory<Thread> */
final class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'is_direct' => false,
            'is_public' => false,
            'everyone_can_join' => false,
            'last_activity_at' => now(),
        ];
    }

    public function public(): self
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }

    public function private(): self
    {
        return $this->state(fn (): array => [
            'is_public' => false,
            'everyone_can_join' => false,
        ]);
    }

    public function direct(): self
    {
        return $this->state(fn (): array => [
            'name' => null,
            'is_direct' => true,
            'is_public' => false,
            'everyone_can_join' => false,
        ]);
    }

    public function open(): self
    {
        return $this->state(fn (): array => [
            'is_public' => true,
            'everyone_can_join' => true,
        ]);
    }
}
