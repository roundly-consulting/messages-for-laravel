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
            if (! Schema::hasColumn('messaging_messages', 'parent_message_id')) {
                $table->foreignUuid('parent_message_id')
                    ->nullable()
                    ->after('thread_id')
                    ->constrained('messaging_messages')
                    ->nullOnDelete();
            }
        });
    }
};
