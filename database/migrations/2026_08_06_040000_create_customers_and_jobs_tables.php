<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first vertical slice: Customer → Job (plan §18 Phase 2).
 *
 * The point is not the feature. It is proving, end to end, that tenant
 * isolation, roll-up visibility, permission enforcement, settings resolution and
 * audit durability actually work together on real business data — which a
 * foundation alone cannot demonstrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Human-facing record numbers.
         *
         * Separate from the primary key on purpose. Primary keys are UUIDv7
         * because sequential ones leak business volume through the API (§5); the
         * NUMBER is what a customer reads down the phone, and it has to be short,
         * ordered and stable.
         *
         * One row per (organization, sequence). Allocation takes a row lock, so
         * two simultaneous creates cannot be handed the same number — a
         * MAX(number)+1 query would do exactly that under any real load.
         */
        Schema::create('record_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // 'customer', 'job'. Not an enum: invoices and estimates land here
            // later, and an enum change is a table rewrite.
            $table->string('sequence_key', 40);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'sequence_key']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            /*
             * The owning franchise (§5.2) — ONE franchise, accountable for the
             * relationship, and what roll-up counts are summed by so an
             * organization total has no double-counting.
             *
             * "Served by" is deliberately NOT a column. It is derived from jobs,
             * because a hand-maintained list goes stale the first time somebody
             * forgets to update it, and a stale accountability tag is worse than
             * none.
             *
             * Reassignable, and audited when it changes: a customer relocating is
             * real, but it must be a deliberate act rather than a side effect of
             * one franchise doing one job.
             */
            $table->uuid('owning_franchise_id');

            // Unique ORGANIZATION-WIDE, not per franchise — customers are shared
            // (§5.2), so two franchises numbering their own customers from 1
            // would produce two different people called C-00001 in one search.
            $table->string('number', 40);

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();

            // US-only at launch (§9). Structured rather than one blob so
            // duplicate detection and, later, service areas have something to
            // match on.
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('postal_code', 12)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            // Soft-deleted: customers carry audit and legal significance (§5), and
            // a job history pointing at a vanished customer is unreadable.
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owning_franchise_id')->references('id')->on('franchises')->restrictOnDelete();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'owning_franchise_id']);
            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'phone']);
            $table->index(['organization_id', 'email']);
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Jobs are NEVER shared (§5.2). A job happens at one place, on one
            // date, with one crew. Visible in roll-ups; never co-owned.
            $table->uuid('franchise_id');
            $table->uuid('customer_id');

            $table->string('number', 40);
            $table->string('title');
            $table->text('description')->nullable();

            // Validated against the `jobs.statuses` setting rather than an enum:
            // permitted values are tenant configuration (§2.1 category 5), and an
            // enum column would need a migration every time an organization
            // wanted its own word for "on hold".
            $table->string('status', 60);

            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();

            // Integer minor units, never a float (§7). The currency column stays
            // even though it is always USD — adding one later is easy, but
            // back-filling the CORRECT currency onto historical rows is guesswork.
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('USD');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'franchise_id', 'scheduled_for']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'status']);
        });

        /*
         * Belt and braces on the tenant boundary (§5.1).
         *
         * The application already refuses to write a row whose organization_id
         * does not match the context, but application code is where the bug will
         * be. These say a customer's franchise and a job's customer must live in
         * the SAME organization as the row pointing at them — so a cross-tenant
         * reference is impossible at the storage layer, not merely unlikely.
         *
         * MySQL only: SQLite cannot add constraints after the fact, and the test
         * database is MySQL precisely so this is exercised rather than skipped.
         */
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('customers', function (Blueprint $table) {
                $table->unique(['organization_id', 'id'], 'customers_org_id_unique');
            });

            Schema::table('franchises', function (Blueprint $table) {
                $table->unique(['organization_id', 'id'], 'franchises_org_id_unique');
            });

            Schema::table('customers', function (Blueprint $table) {
                $table->foreign(['organization_id', 'owning_franchise_id'], 'customers_franchise_same_org')
                    ->references(['organization_id', 'id'])->on('franchises')->restrictOnDelete();
            });

            Schema::table('jobs', function (Blueprint $table) {
                $table->foreign(['organization_id', 'franchise_id'], 'jobs_franchise_same_org')
                    ->references(['organization_id', 'id'])->on('franchises')->restrictOnDelete();

                $table->foreign(['organization_id', 'customer_id'], 'jobs_customer_same_org')
                    ->references(['organization_id', 'id'])->on('customers')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('record_sequences');
    }
};
