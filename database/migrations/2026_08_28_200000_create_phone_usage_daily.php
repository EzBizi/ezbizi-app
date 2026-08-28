<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each company's phone service actually costs.
 *
 * EzBizi pays Twilio for every tenant's traffic out of one bill and has no way
 * to see what any one company spent. Spec §5 puts invoicing out of scope —
 * but you cannot decide to bill later using data you never collected, and
 * Twilio does not retain usage records forever. So this starts collecting now
 * and stays a read-only record until somebody decides what to do with it.
 *
 * One row per (connection, day, category). Category is Twilio's own — calls,
 * sms, recordings, transcriptions, phonenumbers — so a company can be told
 * where its money went rather than just how much.
 *
 * Prices are stored as the decimal string Twilio sends, in its currency.
 * Floats would round money, and this is the table somebody may one day invoice
 * from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_usage_daily', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();

            $table->date('day');
            $table->string('category', 64);
            $table->string('description', 191)->nullable();

            $table->decimal('usage', 18, 4)->default(0);
            $table->string('usage_unit', 32)->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->string('count_unit', 32)->nullable();

            $table->decimal('price', 14, 6)->default(0);
            $table->string('price_unit', 8)->default('USD');

            $table->timestamps();

            // Re-running a day must correct it, not double it. The importer
            // upserts on this key precisely so a retried night is safe.
            $table->unique(['connection_id', 'day', 'category'], 'phone_usage_daily_unique');
            $table->index(['organization_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_usage_daily');
    }
};
