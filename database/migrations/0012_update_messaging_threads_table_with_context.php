<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * An optional polymorphic context on a thread — the company, project or order a conversation
 * belongs to. Nullable: a thread without one behaves exactly as before.
 *
 * `context_id` is an OUTBOUND key — it points at a host model, like the sender and participant
 * morphs — so it follows `messages.key_type`, not `messages.primary_key_type`. `morphKey()`
 * adds the composite (`context_type`, `context_id`) index every context lookup narrows on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $morphKeyType = KeyType::fromConfig('messages.key_type');

        Schema::table('messaging_threads', function (Blueprint $table) use ($morphKeyType): void {
            if (! Schema::hasColumn('messaging_threads', 'context_type')) {
                $table->morphKey('context', $morphKeyType, nullable: true);
            }
        });
    }
};
