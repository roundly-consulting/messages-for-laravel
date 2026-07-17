<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messaging_messages', 'type')) {
                $table->string('type')->default('text')->index()->after('message');
            }

            if (! Schema::hasColumn('messaging_messages', 'meta')) {
                $table->jsonb('meta')->nullable()->after('type');
            }
        });
    }
};
