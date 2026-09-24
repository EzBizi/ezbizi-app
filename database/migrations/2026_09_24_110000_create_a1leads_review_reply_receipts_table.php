<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts for Google review replies posted from the A1Leads GBP tab.
 *
 * A uuid `id` rather than the composite key Rodrigo's handoff shipped with: the
 * privacy sweep erases a column by saving the ROW, which needs one key to
 * address it by. The pair stays unique, which is the rule the composite key
 * existed to hold. Changed before this migration ever ran anywhere - the gate
 * that would have deployed it refused it for having no retention policy.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('a1leads_review_reply_receipts');
    }
};
