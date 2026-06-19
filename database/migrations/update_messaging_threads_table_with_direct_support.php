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

        // Direct (1:1) threads have no name; relax the existing NOT NULL constraint.
        Schema::table('messaging_threads', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
        });
    }
};
