<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Messages\Commands\PruneMessagesCommand;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\MessagesServiceProvider;

it('registers the messages manager as a singleton', function (): void {
    expect(app(MessagesManager::class))->toBe(app(MessagesManager::class));
});

it('merges the packaged config', function (): void {
    expect(config('messages.models.thread'))->not->toBeNull()
        ->and(config('messages.preview.length'))->toBe(120);
});

it('registers the prune command', function (): void {
    expect(array_keys(app('Illuminate\Contracts\Console\Kernel')->all()))->toContain('messages:prune');
})->skip(fn (): bool => ! class_exists(PruneMessagesCommand::class));

it('loads the package translations', function (): void {
    expect(trans('messages::messages.preview.deleted'))->not->toBe('messages::messages.preview.deleted');
});

/**
 * Publish-only migrations (fleet policy). A bare `php artisan migrate` in a host must
 * NOT create the package's tables — the host publishes them first. This pins the
 * policy against a regression that re-adds `loadMigrationsFrom()`.
 */
it('never auto-loads its migrations', function (): void {
    $packageMigrations = realpath(__DIR__.'/../../database/migrations');

    $loaded = array_map(
        static fn (string $path): string => (string) realpath($path),
        app('migrator')->paths(),
    );

    expect($loaded)->not->toContain($packageMigrations);
});

it('publishes each migration into the host migrations directory under a timestamped name', function (): void {
    $paths = ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-migrations');

    expect($paths)->toHaveCount(11);

    foreach ($paths as $source => $target) {
        expect($source)->toEndWith('.php')
            ->and(dirname((string) $target))->toBe(database_path('migrations'))
            ->and(basename((string) $target))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_\d{4}_\w+\.php$/');
    }
});

it('keeps every publish tag byte-identical', function (): void {
    $config = ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-config');
    expect(array_values($config))->toBe([config_path('messages.php')]);

    $translations = ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-translations');
    expect(array_values($translations))->toBe([app()->langPath('vendor/messages')]);

    $resources = ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-resources');
    expect(array_values($resources))->toBe([
        app_path('Http/Resources/Messages/ThreadResource.php'),
        app_path('Http/Resources/Messages/MessageResource.php'),
        app_path('Http/Resources/Messages/ParticipantResource.php'),
    ]);

    $notifications = ServiceProvider::pathsToPublish(MessagesServiceProvider::class, 'messages-notifications');
    expect(array_values($notifications))->toBe([app_path('Notifications/Messages/NewMessageNotification.php')]);
});

it('contributes a messages section to about', function (): void {
    $this->artisan('about', ['--only' => 'messages'])
        ->expectsOutputToContain('Thread model')
        ->expectsOutputToContain('Prune retention')
        ->assertExitCode(0);
});

/**
 * Message bodies, participants and host topology never reach the `about` output. The
 * section reports switches, counts and bounds only.
 */
it('never renders host topology or configured lists in the about section', function (): void {
    config()->set('messages.media.disk', 'tenant-private-uploads');
    config()->set('messages.media.accepted_mime_types', ['image/png', 'application/x-internal-dossier']);
    config()->set('messages.media.responsive_widths', [320, 640, 1280]);
    config()->set('messages.notifications.enabled', true);
    config()->set('messages.notifications.channels', ['database', 'slack-internal-ops']);

    $this->artisan('about', ['--only' => 'messages'])
        ->doesntExpectOutputToContain('tenant-private-uploads')
        ->doesntExpectOutputToContain('application/x-internal-dossier')
        ->doesntExpectOutputToContain('slack-internal-ops')
        ->assertExitCode(0);
});

it('reports configured lists as counts', function (): void {
    config()->set('messages.media.accepted_mime_types', ['image/png', 'image/jpeg']);

    $this->artisan('about', ['--only' => 'messages'])
        ->expectsOutputToContain('2 mime type(s)')
        ->assertExitCode(0);
});

it('reports the attachment disk as presence only', function (): void {
    config()->set('messages.media.disk', null);

    $this->artisan('about', ['--only' => 'messages'])
        ->expectsOutputToContain('MEDIA DEFAULT')
        ->assertExitCode(0);
});
