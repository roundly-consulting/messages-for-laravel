<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Interfaces;

interface ParticipatesInMessaging
{
    /** @return array<string, mixed> */
    public function participateAs(): array;
}
