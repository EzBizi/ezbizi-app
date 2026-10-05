<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The membership program, inside Customers (Jose, 2026-10-05): a yearly
 * maintenance plan a customer signs up for, reminded a month before the year
 * is up. Spec: docs/superpowers/specs/2026-10-05-memberships-design.md.
 *
 * One `memberships` row is ONE YEAR. A renewal is a new row with enrollment
 * 'renewal', so a customer's history is their rows and nothing is overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3)->default('USD');
            // Jose: "either, per plan" — one charge a year, or twelve.
            $table->string('billing', 8)->default('yearly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // The customer's owning franchise when they joined: reach and the
            // automation scope both read it, as they do a job's.
            $table->foreignUuid('franchise_id')->constrained();
            $table->foreignUuid('customer_id')->constrained();
            $table->foreignUuid('membership_plan_id')->constrained();
            $table->string('enrollment', 8)->default('new');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('cancelled_at')->nullable();
            // Set when membership.renewal_due was raised, so it is raised once.
            $table->timestamp('reminded_at')->nullable();
            // ponytail: one running total typed by staff; a payments ledger
            // when payments arrive from somewhere other than a person.
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
    }
};
