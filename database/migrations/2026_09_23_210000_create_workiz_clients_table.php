<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workiz clients — DERIVED from the job stream, not fetched.
 *
 * Workiz has no client endpoint. The whole v1 surface is Jobs, Leads, Team and
 * TimeOff; a client exists only as a `ClientId` integer plus contact fields
 * denormalised onto every job. So this table is built by grouping
 * `workiz_jobs`, which costs no API call and spends none of the quota that
 * already refuses the sweep with 429.
 *
 * Two consequences that are properties of Workiz rather than choices:
 *
 *  - **A client with no job is invisible.** Nothing recovers them.
 *  - The contact details are a **snapshot at a job**, so the most recent job
 *    wins. Which job that is can change as the mirror refreshes.
 *
 * ── `ClientId` IS ACCOUNT-SCOPED, AND THE NUMBERS PROVE IT ──────
 *
 * Measured across 7,793 of A-1's real records: **5,337 distinct `ClientId`
 * values but 7,225 distinct (account, ClientId) pairs.** The same integer is a
 * different person in a different account. Keyed on `client_id` alone this
 * table would merge about 1,888 clients into strangers, silently, in the
 * direction nobody checks. Hence `unique(franchise_id, client_id)` — the
 * franchise rather than the connection, because disconnect deletes the
 * connection row and a reconnect mints a new id.
 *
 * ── `customer_id` IS THE LINK, AND IT LIVES HERE ────────────────
 *
 * The same ruling the Jobs session made for `workiz_jobs.job_id`: one more
 * importer must not make `customers` grow a column per integration. It is also
 * what makes the projection idempotent — a client that already names a customer
 * is never projected again — and what makes it obey the house rule that a
 * recurring sweep never overwrites what a person has edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('franchise_id');
            $table->uuid('connection_id')->nullable();

            // Workiz's ClientId. Meaningless without the franchise beside it.
            $table->unsignedBigInteger('client_id');

            $table->string('name')->nullable();
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_digits', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('unit')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 8)->nullable();

            // The EzBizi customer this became, once the projection has run.
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // The job these details were taken from, so "most recent wins" is
            // inspectable rather than implied.
            $table->string('from_remote_job_id')->nullable();
            $table->dateTime('last_seen_at')->nullable();

            $table->dateTime('synced_at');
            $table->timestamps();

            $table->unique(['franchise_id', 'client_id']);
            $table->index(['organization_id', 'customer_id']);
            $table->index('phone_digits');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workiz_clients');
    }
};
