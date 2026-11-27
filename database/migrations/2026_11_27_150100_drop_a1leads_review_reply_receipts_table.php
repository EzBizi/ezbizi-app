<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A1LeadsReplyReceipt had no writer and no reader left (desk cuts, 2026-11-27).
 * Production held 0 rows when this was written; the create migration stays
 * untouched because it is deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('a1leads_review_reply_receipts');
    }

    public function down(): void
    {
        Schema::create('a1leads_review_reply_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('google_review_name', 300);
            $table->text('reply_text');
            $table->timestamp('sent_at')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'google_review_name'], 'a1leads_reply_receipt_unique');
            $table->index('created_at');
        });
    }
};
