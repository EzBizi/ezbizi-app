<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated join / leave events (TODO #27).
 *
 * `users.is_active` remains the current switch. This table is the history.
 * Leaving history begins at this migration — do not invent `left` dates from
 * `users.updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('kind'); // started | left
            $table->date('occurred_on');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['organization_id', 'user_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_events');
    }
};
