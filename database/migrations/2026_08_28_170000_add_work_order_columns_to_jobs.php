<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Jobs becomes a work order (spec §5, §7, §8.1).
 *
 * `job_lines` holds SERVICE lines only. Materials are read from
 * `inventory_issues.job_id` and carry no money at all (§9), so there is no
 * material line here and no cost anywhere on a job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Whose trade this is. Roles are organization-global, so this is
            // scoped to the tenant rather than to the franchise.
            $table->foreignUuid('assigned_role_id')->nullable()->after('status')
                ->constrained('roles')->nullOnDelete();

            // Who actually did it. Personal data — see Job::retention().
            $table->foreignUuid('performed_by_user_id')->nullable()->after('assigned_role_id')
                ->constrained('users')->nullOnDelete();

            // When the money was earned. Revenue is reported against THIS, not
            // created_at: a job booked in June and finished in September is
            // September's revenue, and a closed month must stay closed (§8.1).
            $table->timestamp('earned_at')->nullable()->after('scheduled_for');

            $table->index(['organization_id', 'assigned_role_id']);
            $table->index(['organization_id', 'earned_at']);
        });

        Schema::create('job_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();

            // Nulls if the catalogue row is later removed. The LINE survives,
            // because the work was done whatever happened to the catalogue.
            $table->foreignUuid('service_id')->nullable()
                ->constrained('services')->nullOnDelete();

            // Snapshots, not lookups. A catalogue price is a current fact; a job
            // is a historical one. Raising a price in September must not rewrite
            // what August's jobs came to.
            $table->string('name', 191);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('amount_minor')->default(0);

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'job_id']);
        });

        // Every job that already has a typed total keeps it, as ONE line that
        // says what it is. Not silently dressed up as a service it never was.
        DB::table('jobs')
            ->whereNotNull('amount_minor')
            ->where('amount_minor', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($jobs) {
                $rows = [];

                foreach ($jobs as $job) {
                    $rows[] = [
                        'id'               => (string) Str::uuid(),
                        'organization_id'  => $job->organization_id,
                        'job_id'           => $job->id,
                        'service_id'       => null,
                        'name'             => 'Job total (entered before itemisation)',
                        'unit_price_minor' => $job->amount_minor,
                        'qty'              => 1,
                        'amount_minor'     => $job->amount_minor,
                        'position'         => 0,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('job_lines')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_lines');

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_role_id');
            $table->dropConstrainedForeignId('performed_by_user_id');
            $table->dropColumn('earned_at');
        });
    }
};
