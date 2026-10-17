<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host-owned fixture tables the morph relations and the HasMessaging trait resolve
 * against: stand-ins for tables a host owns, so they live in the suite rather than shipping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('restaurants', fn (Blueprint $table) => $table->id());
        Schema::create('companies', fn (Blueprint $table) => $table->id());
        Schema::create('notifiable_users', fn (Blueprint $table) => $table->id());
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }
};
