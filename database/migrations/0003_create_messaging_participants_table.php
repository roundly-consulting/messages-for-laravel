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

        Schema::create('messaging_participants', function (Blueprint $table) use ($keyType): void {
            match ($keyType) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $thread = match ($keyType) {
                KeyType::BigInt => $table->foreignId('thread_id'),
                KeyType::Uuid => $table->foreignUuid('thread_id'),
                KeyType::Ulid => $table->foreignUlid('thread_id'),
            };
            $thread->references('id')->on('messaging_threads')->onDelete('cascade');

            $table->morphs('participant');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
