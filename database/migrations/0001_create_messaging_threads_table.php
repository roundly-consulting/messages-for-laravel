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
        // The inbound primary key: other packages' polymorphic columns point at this id, and
        // those default to `bigint`. Defaulting anything else makes a thread unrelatable on a
        // strict engine. Every internal FK in this package tracks this same key.
        $keyType = KeyType::fromConfig('messages.primary_key_type');

        Schema::create('messaging_threads', function (Blueprint $table) use ($keyType): void {
            match ($keyType) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->string('name');
            $table->boolean('is_public')->default(false);
            $table->boolean('everyone_can_join')->default(false);
            $table->timestamp('last_activity_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
