<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (Jose, 2026-10-20/21): one invoice model for the whole product —
 * a head office billing its branches from a charge sheet, and (Jobs, later) a
 * company billing its customer for a job. Lines are a snapshot taken at issue;
 * payments carry charges and refunds; amount due is worked out, never stored.
 * A sent invoice is voided, never deleted.
 *
 * Charge sheets: per period, one row per branch, one column per charge line
 * the organization configured (financial_charge_columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_charge_columns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Optional: the column of the organization's "expenses by company"
            // Airtable source this charge is read from (field name there).
            $table->string('source_column')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_charge_sheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete(); // the issuer; null = organization
            $table->date('period_from');
            $table->date('period_to');
            $table->string('title', 160)->nullable();
            $table->json('columns'); // [{id, name}] as they stood when the sheet was made
            $table->string('status', 16)->default('draft'); // draft | invoiced
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('financial_charge_sheet_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sheet_id')->constrained('financial_charge_sheets')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 160);
            $table->unsignedInteger('jobs')->nullable();
            $table->json('cells'); // column id => cents
            $table->uuid('invoice_id')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // The issuing place; null company = the organization itself.
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->string('status', 16)->default('draft'); // draft | approved | sent | paid | void
            $table->string('currency', 3)->default('USD');
            // Who is billed: a Customer (a job's invoice), or a Franchise/Company (a branch charge).
            $table->string('bill_to_type', 32);
            $table->uuid('bill_to_id')->nullable();
            $table->string('bill_to_name', 200);
            $table->text('bill_to_address')->nullable();
            $table->json('bill_to_emails')->nullable();
            // What it came from: a charge sheet row, a Job, or nothing (typed in).
            $table->string('source_type', 32)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('summary', 200)->nullable(); // e.g. "Total jobs: 78"
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->string('pdf_disk', 32)->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamp('overdue_announced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status', 'due_on']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('description', 500);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->bigInteger('unit_cents');
            $table->bigInteger('amount_cents');
            $table->boolean('taxable')->default(false);
            $table->unsignedInteger('tax_rate_bp')->default(0); // basis points: 6.625% = 663
            $table->bigInteger('tax_cents')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8)->default('charge'); // charge | refund
            $table->date('paid_on');
            $table->bigInteger('amount_cents');
            $table->bigInteger('tip_cents')->default(0);
            $table->bigInteger('fee_cents')->default(0);
            $table->string('method', 32); // cash | check | card | transfer | other
            $table->string('reference', 120)->nullable();
            $table->string('provider', 32)->nullable(); // e.g. stripe
            $table->string('provider_reference', 120)->nullable();
            $table->string('payout_id', 120)->nullable();
            $table->foreignUuid('collected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('financial_charge_sheet_rows');
        Schema::dropIfExists('financial_charge_sheets');
        Schema::dropIfExists('financial_charge_columns');
    }
};
