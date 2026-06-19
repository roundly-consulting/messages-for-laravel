<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_participants', function (Blueprint $table): void {
            if (! Schema::hasColumn('messaging_participants', 'last_read_message_id')) {
                $table->uuid('last_read_message_id')->nullable()->after('read_at');
            }
        });
    }
};
