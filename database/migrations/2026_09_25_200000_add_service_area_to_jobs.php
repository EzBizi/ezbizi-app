<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The service area a job belongs to -- a sub-division of its franchise
 * (Jose, 2026-09-25), agreed with the Scheduling session.
 *
 * Nullable, because "no area" is a real and deliberate answer: a job whose
 * assigned area is another franchise's, or whose zip falls in no area, is left
 * empty rather than given a wrong one (JobServiceArea). nullOnDelete, so
 * removing an area leaves its jobs unplaced rather than deleting them.
 *
 * The index is shaped for Scheduling's booked count, which reads this column:
 * BelongsToTenant puts organization_id first, then the area, then a half-open
 * [start, end) range on scheduled_for per day. Scheduling confirmed the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->foreignUuid('service_area_id')->nullable()->after('franchise_id')
                ->constrained('service_areas')->nullOnDelete();

            $table->index(['organization_id', 'service_area_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'service_area_id', 'scheduled_for']);
            $table->dropConstrainedForeignId('service_area_id');
        });
    }
};
