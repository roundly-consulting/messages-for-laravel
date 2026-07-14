<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('thread_id')->references('id')->on('messaging_threads')->onDelete('cascade');
            $table->morphs('participant');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
