<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Models\Participant;

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

    public static function ownershipOnlyByTransfer(): self
    {
        $message = trans('messages::messages.participation.ownership-by-transfer');

        return new self(is_string($message) ? $message : 'Ownership changes hands only through transferOwnership().');
    }

    public static function ownerMustTransferFirst(): self
    {
        $message = trans('messages::messages.participation.owner-must-transfer');

        return new self(is_string($message) ? $message : 'The owner must transferOwnership() before leaving the thread.');
    }

    public static function notTheOwner(Model $model): self
    {
        $message = trans('messages::messages.participation.not-the-owner', [
            'participant' => "{$model->getMorphClass()}:{$model->getKey()}",
        ]);

        return new self(is_string($message) ? $message : 'Only the owner can transfer ownership.');
    }

    public static function directThreadHasNoRoles(): self
    {
        $message = trans('messages::messages.participation.direct-has-no-roles');

        return new self(is_string($message) ? $message : 'A direct thread has no roles to change.');
    }

    public static function directThreadIsClosed(): self
    {
        $message = trans('messages::messages.participation.direct-is-closed');

        return new self(is_string($message) ? $message : 'A direct thread takes no new participants.');
    }

    public static function inAnotherThread(Participant $participant): self
    {
        $message = trans('messages::messages.scope.participant-in-another-thread', [
            'participant' => (string) $participant->getKey(),
        ]);

        return new self(is_string($message) ? $message : 'The participant belongs to another thread.');
    }

    public static function participantMissing(Participant $participant): self
    {
        $message = trans('messages::messages.participation.participant-missing', [
            'participant' => (string) $participant->getKey(),
        ]);

        return new self(is_string($message) ? $message : 'The participating model no longer exists.');
    }

    public static function threadMissing(Participant $participant): self
    {
        $message = trans('messages::messages.participation.thread-missing', [
            'participant' => (string) $participant->getKey(),
        ]);

        return new self(is_string($message) ? $message : 'The thread no longer exists.');
    }
}
