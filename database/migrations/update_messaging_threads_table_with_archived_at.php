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
            if (! Schema::hasColumn('messaging_threads', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('last_activity_at');
            }
        });
    }
};
