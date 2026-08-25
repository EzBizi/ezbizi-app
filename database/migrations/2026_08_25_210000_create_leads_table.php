<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries that arrived through a connected advertising account (EZ Leads).
 *
 * One row per enquiry as the SOURCE reported it, plus what EzBizi has since
 * decided about it. The two are kept apart on purpose: `source_status` is
 * Google's word and is overwritten on every sync, `status` is ours and is not.
 * A sweep that reconciled them into one column would have every manual decision
 * silently reverted the next time the sync ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Filed the way a service or an inventory item is — nullable company
            // and franchise, both null meaning organization-wide. The CONNECTION
            // that brings leads in is XOR-scoped (scope_type + scope_id) because
            // that is how every credential on this platform is scoped; a lead is
            // not a credential, it is a record somebody files, and filing it the
            // same way as the rest of the tenant's records is what lets it reuse
            // AuthorizesCatalogPlace instead of growing a second reach model.
            // The sync copies the connection's scope down onto each lead.
            $table->uuid('company_id')->nullable();
            $table->uuid('franchise_id')->nullable();

            // Which connection brought it in. Nullable so a lead outlives the
            // disconnection of its source — losing the connection must not
            // delete the enquiries it already delivered, which is the whole
            // reason they were copied here rather than read live.
            $table->uuid('connection_id')->nullable();

            $table->string('source')->default('local_services');
            $table->string('remote_id');

            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Normalised digits, for search. A person typing 5551234567 and a
            // provider sending +1 (555) 123-4567 are looking for the same lead,
            // and neither should have to know the other's formatting.
            $table->string('phone_digits', 32)->nullable();

            $table->string('lead_type')->nullable();
            $table->string('category')->nullable();
            $table->text('summary')->nullable();

            $table->string('status')->default('new');
            $table->string('source_status')->nullable();
            $table->string('charge_status')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // The idempotency key for the sync. Two runs overlapping, a retry
            // after a timeout, or a cursor replayed from the start all land on
            // the same row instead of a duplicate enquiry in somebody's list.
            $table->unique(['organization_id', 'source', 'remote_id']);

            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'received_at']);
            $table->index('phone_digits');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->nullOnDelete();
            $table->foreign('connection_id')->references('id')->on('social_connections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
