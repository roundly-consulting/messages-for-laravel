<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;

final class ParticipationException extends Exception
{
    public static function interfaceImplementationRequired(?object $class): self
    {
        $message = trans('messages::messages.participation.interface-required', [
            'interface' => ParticipatesInMessaging::class,
            'class' => $class === null ? 'null' : $class::class,
        ]);

        return new self(is_string($message) ? $message : 'Participation interface not implemented.');
    }

    public static function notAParticipant(Model $model): self
    {
        $message = trans('messages::messages.participation.not-a-participant', [
            'participant' => "{$model->getMorphClass()}:{$model->getKey()}",
        ]);

        return new self(is_string($message) ? $message : 'Not a participant of this thread.');
    }
}
