<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Exceptions;

use Exception;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

final class ParticipationException extends Exception
{
    public static function interfaceImplementationRequired(?object $class): self
    {
        $interface = ParticipatesInMessaging::class;
        $className = $class === null ? 'null' : $class::class;

        return new self(
            "Missing implementation of {$interface} interface for class {$className}.",
        );
    }
}
