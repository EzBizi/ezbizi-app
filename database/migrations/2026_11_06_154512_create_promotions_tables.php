<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions (Jose, 2026-11-06): a text to the customers of Done jobs in a
 * date range, sent from Memberships > Promotions. One `promotion_texts` row
 * per phone texted; a reply opens a Promotions lead on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->date('jobs_from');
            $table->date('jobs_to');
            $table->text('body');
            $table->json('franchise_ids');
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_texts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('e164', 32);
            $table->string('contact_name')->nullable();
            $table->string('twilio_sid', 64)->nullable();
            $table->text('body')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['promotion_id', 'e164']);
            $table->index(['organization_id', 'e164', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_texts');
        Schema::dropIfExists('promotions');
    }
};
