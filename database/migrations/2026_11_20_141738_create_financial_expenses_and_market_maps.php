<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QuickBooks Online expenses per market (Shoval via Jose's Monday board,
 * 2026-11-20). One row per expense LINE of a Purchase, Bill or JournalEntry,
 * keyed by the QuickBooks ids so a re-read replaces rather than adds. The
 * market is not stored on the line: it is resolved through
 * financial_market_maps at read time, so remapping a Class or Location moves
 * its history with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // Kept when the connection goes (what was read stays, as Airtable's tables do).
            $table->uuid('social_connection_id')->nullable();
            $table->string('source', 16)->default('quickbooks');
            $table->string('realm_id', 32);
            $table->string('txn_type', 24); // Purchase | Bill | JournalEntry
            $table->string('txn_id', 32);
            $table->string('line_id', 32);
            $table->date('txn_date');
            $table->bigInteger('amount_cents'); // a journal credit to an expense account is negative
            $table->string('account_id', 32)->nullable();
            $table->string('account_name', 191)->nullable();
            $table->string('account_type', 48)->nullable();
            $table->string('vendor_id', 32)->nullable();
            $table->string('vendor_name', 191)->nullable();
            $table->string('class_id', 32)->nullable();
            $table->string('class_name', 191)->nullable();
            $table->string('department_id', 32)->nullable();
            $table->string('department_name', 191)->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'realm_id', 'txn_type', 'txn_id', 'line_id'], 'financial_expenses_qbo_unique');
            $table->index(['organization_id', 'txn_date']);
        });

        Schema::create('financial_market_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('realm_id', 32);
            $table->string('dimension', 16); // class | department (QuickBooks "Location")
            $table->string('remote_id', 32);
            $table->string('name', 191);
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            // name: matched to a branch by name on sync; person: somebody chose; none: left unassigned on purpose.
            $table->string('mapped_by', 16)->default('name');
            $table->timestamps();
            $table->unique(['organization_id', 'realm_id', 'dimension', 'remote_id'], 'financial_market_maps_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_market_maps');
        Schema::dropIfExists('financial_expenses');
    }
};
