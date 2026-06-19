<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Messages\MessagesServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            MessagesServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');

        // Realtime broadcasting stays off by default; tests opt in per case.
        $app['config']->set('messages.broadcasting.enabled', false);

        $this->setupDatabase();
    }

    protected function setupDatabase(): void
    {
        Schema::dropAllTables();

        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $file) {
            (include $file)->up();
        }

        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('restaurants', fn (Blueprint $table) => $table->id());
        Schema::create('companies', fn (Blueprint $table) => $table->id());
        Schema::create('notifiable_users', fn (Blueprint $table) => $table->id());
    }
}
