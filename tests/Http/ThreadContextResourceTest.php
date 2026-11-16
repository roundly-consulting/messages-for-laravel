<?php

declare(strict_types=1);

use App\Http\Resources\Messages\ThreadResource as PublishedThreadResource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Http\Resources\ThreadContextResource;
use RoundlyConsulting\Messages\Http\Resources\ThreadResource;
use RoundlyConsulting\Messages\MessagesServiceProvider;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\Messages\Tests\Fixtures\AbstractParticipantResource;
use RoundlyConsulting\Messages\Tests\Fixtures\HostContextResource;
use RoundlyConsulting\Messages\Tests\Models\Company;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * A thread's context is a host model, so it renders like every other host reference: through a
 * swappable resource (`messages.resources.context`) holding its key and class basename — never
 * `context_type`, which without a morph map IS the class name.
 */

afterEach(function (): void {
    Relation::morphMap([], false);
});

function contextThreadJson(JsonResource $resource): string
{
    return (string) $resource->toResponse(Request::create('/'))->getContent();
}

it('renders a loaded context by key and class basename, never a class name', function (): void {
    $company = Company::create();
    $thread = Messages::start('Deal')->for($company)->withParticipant(User::create())->create()->load('context');

    $resource = ThreadResource::make($thread);

    expect($resource->resolve(Request::create('/'))['context'])->toBe([
        'id' => $company->getKey(),
        'type' => 'Company',
    ])
        ->and(contextThreadJson($resource))->not->toContain('\\\\')
        ->not->toContain('context_type');
});

it('renders the basename under a morph map, not the alias', function (): void {
    Relation::morphMap(['tenant' => Company::class]);

    $company = Company::create();
    $thread = Messages::start('Deal')->for($company)->create()->load('context');

    expect($thread->context_type)->toBe('tenant')
        ->and(ThreadResource::make($thread)->resolve(Request::create('/'))['context']['type'])->toBe('Company');
});

it('leaves context out until the relation is loaded, and renders null for none', function (): void {
    $company = Company::create();
    $inContext = Messages::start('Deal')->for($company)->create();
    $plain = Messages::start('Plain')->create()->load('context');

    expect(ThreadResource::make($inContext)->resolve(Request::create('/')))->not->toHaveKey('context')
        ->and(ThreadResource::make($plain)->resolve(Request::create('/'))['context'])->toBeNull()
        ->and(ThreadContextResource::configured(null))->toBeNull();
});

it('renders the context through the configured host resource', function (): void {
    config()->set('messages.resources.context', HostContextResource::class);

    $company = Company::create();
    $thread = Messages::start('Deal')->for($company)->create()->load('context');

    expect(ThreadResource::make($thread)->resolve(Request::create('/'))['context'])->toBe([
        'company' => $company->getKey(),
        'kind' => 'host',
    ]);
});

it('reads a blank context resource as the default (strict config)', function (mixed $value): void {
    config()->set('messages.resources.context', $value);

    expect(MessagesConfig::contextResource())->toBe(ThreadContextResource::class);
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['  '],
]);

it('refuses a context resource that is not a JsonResource (strict config)', function (mixed $value): void {
    config()->set('messages.resources.context', $value);

    $thread = Messages::start('Deal')->for(Company::create())->create()->load('context');

    expect(fn (): array => ThreadResource::make($thread)->resolve(Request::create('/')))
        ->toThrow(InvalidConfigurationException::class, 'messages.resources.context');
})->with([
    'missing class' => ['App\\Http\\Resources\\Missing'],
    'not a resource' => [User::class],
    'the bare base class' => [JsonResource::class],
    'an abstract resource' => [AbstractParticipantResource::class],
    'not a string' => [[ThreadContextResource::class]],
]);

it('renders the context in the published thread stub exactly like the package resource', function (?string $contextResource): void {
    foreach (ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-resources') as $from => $to) {
        require_once (string) $from;
    }

    config()->set('messages.resources.context', $contextResource);

    $thread = Messages::start('Deal')->for(Company::create())->create()->load('context');

    expect(contextThreadJson(PublishedThreadResource::make($thread)))->toBe(contextThreadJson(ThreadResource::make($thread)))
        ->toContain('"context":');
})->with([
    'default resource' => [null],
    'host resource' => [HostContextResource::class],
]);
