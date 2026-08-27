<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking for phone service, and EzBizi staff deciding (§4 gate 2).
 *
 * ── Why this exists ──────────────────────────────────────────────
 *
 * Phone is a paid extra. On the provisioned path the Twilio usage bills
 * EzBizi, not the tenant (spec §5), so "any administrator may switch it on"
 * meant any customer could spend EzBizi's balance with nobody asked.
 *
 * ModuleAvailability's docblock names the four gates and records that the
 * second — entitlement — was never built. This is it, for Phone only. It is
 * deliberately not a general "requestable modules" framework: there is one
 * paid extra today, and a second one can learn from this one.
 *
 * ── Why the decision is per company, not per request ─────────────
 *
 * A request names several companies; the answer is often "yes to that one,
 * not yet to the other". Storing one status on the request would force a
 * decline for the whole thing, and a decline starts a week's cooling-off — so
 * the customer would wait a week to be told what could have been said now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_service_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Who asked. Kept when they leave — nullOnDelete rather than
            // cascade, because the request is the organization's record of
            // having asked, not that person's.
            $table->foreignUuid('requested_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('status', 16);

            // What they told us, which is what the decision is made on.
            $table->string('expected_volume', 32);
            $table->boolean('wants_sms')->default(false);
            $table->text('purpose');

            // Platform staff, not a tenant user — a different table, hence no
            // foreign key to `users`.
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('phone_service_request_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('request_id')
                ->constrained('phone_service_requests')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            // Null until somebody decides. Three states in a nullable boolean
            // rather than a fourth status string: pending / yes / no is the
            // whole vocabulary here.
            $table->boolean('approved')->nullable();

            $table->timestamps();

            $table->unique(['request_id', 'company_id']);

            // The entitlement check reads this: "is there an approved row for
            // this company". Indexed because it is asked on every attempt to
            // activate phone service.
            $table->index(['company_id', 'approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_service_request_companies');
        Schema::dropIfExists('phone_service_requests');
    }
};
