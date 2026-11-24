<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financials › Bank (Jose, 2026-11-24: Plaid approved). A tenant's own bank
 * and card accounts, read through Plaid (PlaidSync). Both tables hang off the
 * Plaid connection (social_connections, one per bank login) and go with it:
 * disconnecting a bank removes what was read from it.
 *
 * Amounts are Plaid's sign: positive is money out of the account, negative is
 * money in. Balances are what Plaid last reported, with when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_connection_id')->constrained()->cascadeOnDelete();
            $table->string('plaid_account_id', 64);
            $table->string('name', 191);
            $table->string('official_name', 191)->nullable();
            $table->string('mask', 8)->nullable();
            $table->string('type', 24)->nullable();     // depository | credit | loan | investment | other
            $table->string('subtype', 48)->nullable();  // checking | savings | credit card | ...
            $table->bigInteger('current_cents')->nullable();
            $table->bigInteger('available_cents')->nullable();
            $table->bigInteger('limit_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('balances_at')->nullable();
            $table->timestamps();
            $table->unique(['social_connection_id', 'plaid_account_id']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->constrained()->cascadeOnDelete();
            $table->string('plaid_transaction_id', 64);
            $table->date('date');
            $table->date('authorized_date')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->nullable();
            $table->string('name', 255);
            $table->string('merchant_name', 191)->nullable();
            $table->string('category', 64)->nullable();         // personal_finance_category.primary
            $table->string('category_detailed', 96)->nullable();
            $table->string('payment_channel', 16)->nullable();
            $table->boolean('pending')->default(false);
            $table->timestamps();
            $table->unique(['social_connection_id', 'plaid_transaction_id'], 'bank_transactions_plaid_unique');
            $table->index(['organization_id', 'date']);
            $table->index(['bank_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
