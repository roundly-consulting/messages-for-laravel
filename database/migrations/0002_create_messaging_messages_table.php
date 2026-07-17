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

        Schema::create('messaging_messages', function (Blueprint $table) use ($keyType): void {
            match ($keyType) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            // A real FK, so it must track the threads PK exactly or the constraint is
            // uncreatable. Laravel's own foreign* helpers keep the column type and the
            // constraint in one place.
            $thread = match ($keyType) {
                KeyType::BigInt => $table->foreignId('thread_id'),
                KeyType::Uuid => $table->foreignUuid('thread_id'),
                KeyType::Ulid => $table->foreignUlid('thread_id'),
            };
            $thread->references('id')->on('messaging_threads')->onDelete('cascade');

            $table->nullableMorphs('sender');
            $table->text('message');
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
