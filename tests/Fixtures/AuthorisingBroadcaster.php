<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A broadcaster that broadcasts nothing but authorises like a real one: Laravel's own channel
 * matching, parameter extraction and guard handling, so the callbacks registered by
 * `Messages::channels()->register()` run exactly as they would behind `/broadcasting/auth`.
 */
final class AuthorisingBroadcaster extends Broadcaster
{
    /**
     * The guards the checks asked the request for.
     *
     * @var list<string|null>
     */
    public array $guardsAsked = [];

    /**
     * Whether the user may subscribe to the channel (named without its `private-` prefix).
     */
    public function allows(mixed $user, string $channel): bool
    {
        $request = Request::create('/broadcasting/auth', 'POST');
        $request->setUserResolver(function (?string $guard = null) use ($user): mixed {
            $this->guardsAsked[] = $guard;

            return $user;
        });

        try {
            return $this->verifyUserCanAccessChannel($request, $channel) === true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }

    /**
     * The options the callback matching the channel was registered with.
     *
     * @return array<string, mixed>
     */
    public function optionsFor(string $channel): array
    {
        return $this->retrieveChannelOptions($channel);
    }

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = []): void {}
}
