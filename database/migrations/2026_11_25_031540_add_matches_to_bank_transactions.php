<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financials › Bank: money that came into the bank, matched to what EzBizi
 * already knows it was for (BankMatcher): a payment recorded on an invoice, or
 * an invoice still open. One match per transaction; nothing on the invoice
 * side changes. matched_by says whether a person chose it or the sync did
 * (only when there was exactly one candidate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('matched_type', 24)->nullable()->after('pending'); // invoice_payment | invoice
            $table->uuid('matched_id')->nullable()->after('matched_type');
            $table->string('matched_by', 8)->nullable()->after('matched_id');  // auto | person
            $table->timestamp('matched_at')->nullable()->after('matched_by');
            $table->index(['organization_id', 'matched_type', 'matched_id'], 'bank_transactions_matched');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex('bank_transactions_matched');
            $table->dropColumn(['matched_type', 'matched_id', 'matched_by', 'matched_at']);
        });
    }
};
