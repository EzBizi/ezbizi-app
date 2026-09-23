<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workiz jobs as Workiz reported them, one row per job, per connection.
 *
 * A LANDING TABLE, not the Jobs module's table. Three reasons, all measured
 * rather than preferred:
 *
 *  - A real payload carries 43 keys plus a variable tail of the account's own
 *    custom fields (`pricing`, `system_type`, `time_frames`, `inspection_report`
 *    on A-1's). `jobs` has twelve fillable columns.
 *  - `jobs` has NO address column at all. `postal_code` and `service_area` are
 *    what A-1's entire zip-to-service-area map is derived from, and neither has
 *    anywhere to live there.
 *  - Writing direct would have dropped about thirty fields against an
 *    instruction that said all the available data.
 *
 * `payload` holds the whole thing, so a field nobody flattened is never lost
 * and a new custom field needs no migration. The flattened columns are only
 * the ones something queries.
 *
 * ── SERVICE AREA IS NOT A FRANCHISE ─────────────────────────────
 *
 * 57 distinct service areas across 10 accounts, one account carrying up to 30.
 * And on 31 of A-1's jobs the service area belongs to a DIFFERENT franchise
 * than the connection does (Scheduling's 3a2b2f40) — a branch doing a handful
 * of jobs in another territory.
 *
 * `franchise_id` is right for the job, because `Job`'s own contract says a job
 * belongs to whoever DID it. But anything that reads `service_area` as implying
 * `franchise_id` out of this table is wrong on those rows, and wrong silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // The connection's franchise. Not nullable: a Workiz account is
            // refused outside a branch view, so every row has one.
            $table->uuid('franchise_id');

            // Nullable so the mirror outlives a disconnection — losing the
            // credential must not delete the history it already delivered,
            // which is the whole reason it was copied here. Same reasoning as
            // `leads.connection_id`.
            $table->uuid('connection_id')->nullable();

            // Workiz's `UUID` is a six-character string like DG0KYN, despite
            // the name. Never a uuid column.
            $table->string('remote_id');
            $table->unsignedBigInteger('serial_id')->nullable();

            // Workiz's ClientId, unique only WITHIN an account. Ten disjoint
            // accounts, so it is meaningless without connection_id beside it.
            $table->unsignedBigInteger('client_id')->nullable();

            // Trimmed on write: four live values carry a trailing space
            // ("Milwaukee ", "Connecticut "). The raw string stays in payload.
            $table->string('service_area')->nullable();

            $table->string('postal_code', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 8)->nullable();
            $table->string('address')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('status')->nullable();
            $table->string('sub_status')->nullable();
            $table->string('job_type')->nullable();
            $table->string('job_source')->nullable();

            // Workiz's CreatedDate. NOT created_at, which is import time and
            // says nothing about when the job was booked.
            $table->dateTime('booked_at')->nullable();
            $table->dateTime('scheduled_for')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('last_status_update')->nullable();

            // A person's name as Workiz recorded it, not an EzBizi user.
            // Linking the two needs a human to confirm it and is its own plan.
            $table->string('created_by')->nullable();

            $table->json('payload');
            $table->dateTime('synced_at');

            $table->timestamps();

            $table->unique(['connection_id', 'remote_id']);
            $table->index(['organization_id', 'franchise_id']);
            $table->index(['organization_id', 'service_area', 'postal_code']);
            $table->index(['organization_id', 'booked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workiz_jobs');
    }
};
