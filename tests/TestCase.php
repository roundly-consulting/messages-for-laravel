<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\Messages\MessagesServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    private static ?string $appKey = null;

    private static ?string $secureRoot = null;

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
     * `media` table the attachments bucket persists into, and messages ships its own — then
     * the host-owned fixture tables the morph relations and the HasMessaging trait resolve
     * against (a source, not creates after the migrate, so a kept schema stays kept).
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            MediaLibraryServiceProvider::class,
            MessagesServiceProvider::class,
            __DIR__.'/database/migrations',
        ];
    }

    /**
     * Migrate once per worker and schema signature, then reset only the data between tests.
     */
    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            // Required for the media signed streaming route (URL signatures). One random key
            // per process: the config is part of the kept schema's signature.
            'app.key' => self::$appKey ??= 'base64:'.base64_encode(random_bytes(32)),

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

        if (self::$secureRoot !== null) {
            (new Filesystem)->deleteDirectory(self::$secureRoot);
        }
    }

    /**
     * The `secure` disk's root: a throwaway directory per process, emptied after every test.
     * Under the testbench skeleton's storage/ it was ONE directory for every parallel
     * process — each process's media ids start at 1, so their attachments collided there,
     * and two test files wiped it in beforeEach while another process was asserting a file
     * in it still existed. Per process rather than per test: the config is part of the kept
     * schema's signature, so a root minted per test would migrate every test from scratch.
     */
    private function secureRoot(): string
    {
        return self::$secureRoot ??= sys_get_temp_dir().'/messages-secure-'.bin2hex(random_bytes(6));
    }
}
