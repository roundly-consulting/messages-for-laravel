<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_threads', function (Blueprint $table): void {
            if (! Schema::hasColumn('messaging_threads', 'is_direct')) {
                $table->boolean('is_direct')->default(false)->index()->after('name');
            }
        });

        // One keyed DM per pair: the two participants, normalised (Thread::directKeyFor()).
        // Unique, so two first contacts racing each other cannot both create a DM — the
        // database refuses the second insert. Group threads leave it null, and NULLs never
        // collide on sqlite, MySQL or Postgres. A side is `{length}:{morph type}:{key}`: at most
        // 3 + 1 + 255 (the morph type column) + 1 + 36 (a uuid) = 296 characters, so a pair
        // plus its separator fits in 600 — within MySQL's 3072-byte index limit in utf8mb4.
        Schema::table('messaging_threads', function (Blueprint $table): void {
            if (! Schema::hasColumn('messaging_threads', 'direct_key')) {
                $table->string('direct_key', 600)->nullable()->unique()->after('is_direct');
            }
        });

        // Direct (1:1) threads have no name; relax the existing NOT NULL constraint.
        Schema::table('messaging_threads', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
        });
    }
};
