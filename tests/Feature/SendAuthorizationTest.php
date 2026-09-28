<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\Actions\SignalTyping;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Only the thread's current participants may post in it. Every other write already checked
 * participation; sending did not, so anyone — a stranger, a member who had just been kicked —
 * could post into a private conversation, and typing() broadcast for them too.
 */
beforeEach(function () {
    config()->set('messages.permissions.enabled', true);

    $this->alice = User::create();
    $this->bob = User::create();
    $this->eve = User::create();

    $this->private = Messages::start('Private')->private()->withParticipants([$this->alice, $this->bob])->create();
});

it('refuses a sender who was never in the thread, through every door', function (string $door) {
    $send = match ($door) {
        'model trait' => fn () => $this->eve->sendMessageTo($this->private, 'I am in!'),
        'builder' => fn () => Messages::to($this->private)->from($this->eve)->send('x'),
        'shortcut' => fn () => Messages::send($this->private, $this->eve, 'x'),
        'action' => fn () => app(SendMessage::class)->execute(new SendMessageData($this->private, $this->eve, 'x')),
    };

    expect($send)->toThrow(UnauthorizedMessagingAction::class);

    expect(Message::query()->count())->toBe(0);
})->with(['model trait', 'builder', 'shortcut', 'action']);

it('refuses a participant who has been removed', function () {
    Messages::thread($this->private)->participants()->remove($this->bob, by: $this->alice);

    expect(fn () => $this->bob->sendMessageTo($this->private, 'still here'))
        ->toThrow(UnauthorizedMessagingAction::class);

    expect(Message::query()->where('message', 'still here')->exists())->toBeFalse();
});

it('refuses a stranger on a public thread until they join', function () {
    $open = Messages::start('Open')->public()->everyoneCanJoin()->withParticipants([$this->alice])->create();

    expect(fn () => $this->eve->sendMessageTo($open, 'hi'))->toThrow(UnauthorizedMessagingAction::class);

    $this->eve->joinThread($open);

    expect($this->eve->sendMessageTo($open, 'hi')->message)->toBe('hi');
});

it('lets a participant send', function () {
    Event::fake([MessageSent::class]);

    $message = $this->bob->sendMessageTo($this->private, 'hello');

    expect($message->exists)->toBeTrue();
    Event::assertDispatched(MessageSent::class);
});

it('always allows a system message, which has no sender', function () {
    $message = Messages::to($this->private)->asSystem('messages::messages.system.thread_renamed', ['name' => 'x'])->send();

    expect($message->sender_id)->toBeNull();
});

it('lets trusted server code post as a non-participant when it opts out explicitly', function () {
    $bot = User::create();

    $viaBuilder = Messages::to($this->private)->from($bot)->withoutParticipationCheck()->send('Heads up');
    $viaAction = app(SendMessage::class)->execute(new SendMessageData(
        thread: $this->private,
        sender: $bot,
        body: 'Again',
        requireParticipation: false,
    ));

    expect($viaBuilder->sender->is($bot))->toBeTrue()
        ->and($viaAction->exists)->toBeTrue();
});

it('does not record a refused send on the fake', function () {
    $fake = Messages::fake();

    expect(fn () => $this->eve->sendMessageTo($this->private, 'nope'))->toThrow(UnauthorizedMessagingAction::class);

    $fake->assertNothingSent();
});

describe('typing', function () {
    beforeEach(function () {
        Event::fake([ParticipantTyping::class]);
    });

    it('refuses a non-participant, whether or not broadcasting is on', function (bool $broadcasting) {
        config()->set('messages.broadcasting.enabled', $broadcasting);

        expect(fn () => Messages::thread($this->private)->typing($this->eve))
            ->toThrow(ParticipationException::class);

        Event::assertNotDispatched(ParticipantTyping::class);
    })->with(['broadcasting on' => true, 'broadcasting off' => false]);

    it('refuses a removed participant', function () {
        config()->set('messages.broadcasting.enabled', true);
        Messages::thread($this->private)->participants()->remove($this->bob, by: $this->alice);

        expect(fn () => app(SignalTyping::class)->execute($this->private, $this->bob))
            ->toThrow(ParticipationException::class);

        Event::assertNotDispatched(ParticipantTyping::class);
    });

    it('broadcasts for a participant', function () {
        config()->set('messages.broadcasting.enabled', true);

        $this->private->typing($this->bob);

        Event::assertDispatched(ParticipantTyping::class, fn (ParticipantTyping $event): bool => $event->participant->is($this->bob));
    });
});
