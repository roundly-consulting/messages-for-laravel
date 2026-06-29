<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\Messages\MessagesServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            MediaLibraryServiceProvider::class,
            MessagesServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');

        // Required for the media signed streaming route (URL signatures).
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Realtime broadcasting stays off by default; tests opt in per case.
        $app['config']->set('messages.broadcasting.enabled', false);

        // Media-library: fakeable public disk, GD driver, and a small responsive ladder so
        // variant generation stays fast under test. Attachments stay private (signed streaming).
        $app['config']->set('media.disk', 'public');
        $app['config']->set('media.image_driver', 'gd');
        $app['config']->set('media.responsive.widths', [320, 640]);

        // A plain (non-faked) local disk for private attachments. Faked disks register a
        // temporary-URL callback, so only a real local disk exercises the "cannot presign ->
        // signed streaming route" fallback that private DM attachments rely on.
        $app['config']->set('filesystems.disks.secure', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/secure'),
        ]);

        $this->setupDatabase();
    }

    protected function setupDatabase(): void
    {
        Schema::dropAllTables();

        foreach ($this->migrationFiles() as $file) {
            (include $file)->up();
        }

        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('restaurants', fn (Blueprint $table) => $table->id());
        Schema::create('companies', fn (Blueprint $table) => $table->id());
        Schema::create('notifiable_users', fn (Blueprint $table) => $table->id());
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(__DIR__.'/../database/migrations/*.php') ?: [];

        // Media-library ships the `media` table the attachments bucket persists into.
        $mediaPackage = dirname((string) (new ReflectionClass(MediaLibraryServiceProvider::class))->getFileName(), 2);
        $files = [...$files, ...(glob($mediaPackage.'/database/migrations/*.php') ?: [])];

        return array_values($files);
    }
}
