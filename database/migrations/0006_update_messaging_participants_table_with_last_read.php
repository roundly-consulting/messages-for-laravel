<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('messages.primary_key_type');

        Schema::table('messaging_participants', function (Blueprint $table) use ($keyType): void {
            if (! Schema::hasColumn('messaging_participants', 'last_read_message_id')) {
                // Points at messaging_messages.id (no FK constraint by design), so it tracks
                // the messages PK type all the same.
                $table->ownerKey('last_read_message_id', $keyType, nullable: true, index: false)
                    ->after('read_at');
            }
        });
    }
};
