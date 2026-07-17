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

        Schema::table('messaging_messages', function (Blueprint $table) use ($keyType): void {
            if (! Schema::hasColumn('messaging_messages', 'parent_message_id')) {
                // Self-referential FK: must track the messages PK or the constraint is
                // uncreatable.
                $parent = match ($keyType) {
                    KeyType::BigInt => $table->foreignId('parent_message_id'),
                    KeyType::Uuid => $table->foreignUuid('parent_message_id'),
                    KeyType::Ulid => $table->foreignUlid('parent_message_id'),
                };

                $parent->nullable()
                    ->after('thread_id')
                    ->constrained('messaging_messages')
                    ->nullOnDelete();
            }
        });
    }
};
