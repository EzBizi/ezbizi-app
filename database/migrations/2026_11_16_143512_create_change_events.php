<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app-wide change feed (Jose, 2026-11-16: "The tab needs to be live").
 * One row per record written, ids only; GET changes?since=<id> reads it and
 * ezbizi:prune-change-events drops rows older than a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->string('type', 32);
            $table->string('record_id', 64);
            $table->uuid('company_id')->nullable();
            $table->uuid('franchise_id')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('changed_at');
            // The poll reads one tenant past a cursor; the prune reads by time.
            $table->index(['organization_id', 'id']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_events');
    }
};
