<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card and bank payments on invoices (Payments, Jose 2026-11-06). One Stripe
 * connected account per company, the legal payee; the money itself lands in
 * invoice_payments, which already has provider / provider_reference /
 * payout_id. The unique pair makes a redelivered webhook a no-op in the
 * database, not only in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stripe_account_id', 64)->unique();
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->timestamps();
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_reference']);
        });
        Schema::dropIfExists('payment_accounts');
    }
};
