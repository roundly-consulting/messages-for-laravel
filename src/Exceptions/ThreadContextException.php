<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Exceptions;

use Exception;

final class ThreadContextException extends Exception
{
    /** The thread belongs to another context than the current one (the fail-closed hook). */
    public static function outsideCurrentContext(int|string|null $thread): self
    {
        $text = trans('messages::messages.context.outside-current', [
            'thread' => (string) $thread,
        ]);

        return new self(is_string($text) ? $text : 'The thread belongs to another context.');
    }

    /** A thread was asked to start in another context than the current one. */
    public static function mismatch(): self
    {
        $text = trans('messages::messages.context.mismatch');

        return new self(is_string($text) ? $text : 'A thread can only be started in the current context.');
    }

    /** A context model without a key — a thread cannot point at it. */
    public static function unsaved(): self
    {
        $text = trans('messages::messages.context.unsaved');

        return new self(is_string($text) ? $text : 'A thread context must be a saved model.');
    }

    /** The resolver returned something other than a model or null. */
    public static function invalidResolution(mixed $given): self
    {
        $text = trans('messages::messages.context.invalid-resolution', [
            'given' => get_debug_type($given),
        ]);

        return new self(is_string($text) ? $text : 'The thread context resolver must return a model or null.');
    }
}
