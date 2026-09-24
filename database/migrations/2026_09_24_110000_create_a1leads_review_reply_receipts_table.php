<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a1leads_review_reply_receipts', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('google_review_name', 300);
            $table->text('reply_text');
            $table->timestamp('sent_at')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamps();
            $table->primary(['organization_id', 'google_review_name'], 'a1leads_reply_receipt_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a1leads_review_reply_receipts');
    }
};
