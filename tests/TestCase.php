<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\Messages\MessagesServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    private string $secureRoot = '';

    /**
     * Every provider messages hard-requires, in registration order. A host auto-discovers
     * media-library; the suite must list it or the test environment is a fiction.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            MediaLibraryServiceProvider::class,
            MessagesServiceProvider::class,
        ];
    }

    /**
     * Migration sources by provider class, never by filename — media-library ships the
     * `media` table the attachments bucket persists into, and messages ships its own nine.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            MediaLibraryServiceProvider::class,
            MessagesServiceProvider::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            // Required for the media signed streaming route (URL signatures).
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),

            // Realtime broadcasting stays off by default; tests opt in per case.
            'messages.broadcasting.enabled' => false,

            // Media-library: fakeable public disk, GD driver, and a small responsive ladder so
            // variant generation stays fast under test. Attachments stay private (signed streaming).
            'media.disk' => 'public',
            'media.image_driver' => 'gd',
            'media.responsive.widths' => [320, 640],

            // A plain (non-faked) local disk for private attachments. Faked disks register a
            // temporary-URL callback, so only a real local disk exercises the "cannot presign ->
            // signed streaming route" fallback that private DM attachments rely on.
            'filesystems.disks.secure' => [
                'driver' => 'local',
                'root' => $this->secureRoot(),
            ],
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->secureRoot !== '') {
            (new Filesystem)->deleteDirectory($this->secureRoot);
            $this->secureRoot = '';
        }
    }

    /**
     * The `secure` disk's root: a throwaway directory per test. Under the testbench
     * skeleton's storage/ it was ONE directory for every parallel process — each process's
     * media ids start at 1, so their attachments collided there, and two test files wiped
     * it in beforeEach while another process was asserting a file in it still existed.
     */
    private function secureRoot(): string
    {
        if ($this->secureRoot === '') {
            $this->secureRoot = sys_get_temp_dir().'/messages-secure-'.bin2hex(random_bytes(6));
        }

        return $this->secureRoot;
    }

    /**
     * The host-owned fixture tables the morph relations and the HasMessaging trait resolve
     * against. The packaged migrations come from {@see migrationSources()}; these four are
     * stand-ins for tables a host owns, so they are built here rather than shipped.
     */
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('restaurants', fn (Blueprint $table) => $table->id());
        Schema::create('companies', fn (Blueprint $table) => $table->id());
        Schema::create('notifiable_users', fn (Blueprint $table) => $table->id());
    }
}
