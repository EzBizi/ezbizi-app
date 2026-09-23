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
            //
            // IT IS NOT PART OF THE IDENTITY, and that is the lesson from
            // leads rather than a preference. Disconnecting DELETES the
            // `social_connections` row (`SocialConnectionsController::letGo`),
            // so a reconnect mints a new uuid. Jose reconnected 37 accounts on
            // 2026-09-14 and the rows pointing at the old ids detached — 5,430
            // adopted leads fell to 2,547 in eight minutes with the connection
            // COUNT unchanged, so nothing announced it. Keyed on
            // `connection_id`, this table would instead have landed every job
            // a SECOND time and quietly doubled. See the unique index below.
            $table->uuid('connection_id')->nullable();

            // Set by the projection when this job becomes an EzBizi `jobs` row.
            // The link lives HERE rather than as a `jobs.workiz_id` column —
            // Jobs' ruling, and the same shape as `leads.job_id`: one more
            // importer must not make `jobs` grow a column per integration.
            $table->foreignUuid('job_id')
                ->nullable()
                ->constrained('jobs')
                ->nullOnDelete();

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

            /*
             * KEYED ON THE FRANCHISE, NOT THE CONNECTION.
             *
             * One branch holds one Workiz account, so the franchise is the
             * stable name for "which book this job came from". The connection
             * is not stable: disconnect deletes the row and reconnect mints a
             * new id, and a key containing it would let every job land again
             * under the new id — the table doubling with nothing to show for
             * it. That is the failure leads took at 5,430 rows.
             *
             * And it must NOT be `remote_id` alone: Workiz's `UUID` is six
             * characters and unique only WITHIN an account, so two branches
             * legitimately hold the same one. A key without the franchise
             * would refuse the second branch's real job as a duplicate of the
             * first's. Both halves are pinned by tests.
             */
            $table->unique(['franchise_id', 'remote_id']);
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
