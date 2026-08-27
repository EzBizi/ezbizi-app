<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The locations on a connected Google Business Profile.
 *
 * What the public sees when they look this business up on Maps: the name, the
 * address, the phone number, the opening hours. EzBizi holds a copy so it can
 * say what Google is currently showing, and so a change made on Google's side
 * by somebody nobody here authorised is visible rather than silent.
 *
 * ── A location is NOT a franchise ───────────────────────────────
 *
 * The tempting shortcut, and the wrong one. A company may hold thirty listings
 * and no franchise records at all; a franchise may cover several listings.
 * `franchise_id` here is an optional link a customer sets to say which branch
 * is accountable for a listing — it is never the identity of the listing, and
 * nothing derives one from the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->uuid('company_id')->nullable();
            $table->uuid('franchise_id')->nullable();
            $table->uuid('connection_id')->nullable();

            // Google's own path, kept whole: `locations/{id}`, and the
            // account-qualified `accounts/{a}/locations/{id}` where v4 needs
            // it. Reassembling either from parts is how a write lands on the
            // wrong listing.
            $table->string('remote_name', 512);
            $table->string('account_name', 512)->nullable();

            $table->string('title');
            $table->string('phone')->nullable();
            $table->string('website', 1024)->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->nullable();

            $table->string('primary_category')->nullable();

            // Google's own word for whether the listing is live, and why not
            // when it is not — VERIFIED, SUSPENDED, DUPLICATE, and so on. A
            // suspended listing is invisible on Maps while looking perfectly
            // healthy in this table, which is the single most useful thing
            // this module can tell somebody.
            $table->string('verification_state')->nullable();
            $table->boolean('is_published')->nullable();

            // Opening hours as Google returns them. Stored whole rather than
            // normalised into rows: nothing here edits a single day, the shape
            // is Google's to change, and a schema that mirrored it would have
            // to be migrated every time they added a field.
            $table->json('hours')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'remote_name']);
            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'verification_state']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->nullOnDelete();
            $table->foreign('connection_id')->references('id')->on('social_connections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_locations');
    }
};
