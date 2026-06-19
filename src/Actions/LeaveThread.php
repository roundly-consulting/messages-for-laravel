<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;

final class LeaveThread
{
    public function __construct(
        private readonly RemoveParticipant $removeParticipant,
    ) {}

    public function execute(AddParticipantData $data): void
    {
        $this->removeParticipant->execute($data);
    }
}
