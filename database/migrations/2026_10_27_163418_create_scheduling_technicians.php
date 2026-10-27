<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EzBizi's own technician roster and who is on each job (Jose, 2026-10-27:
 * EzBizi is the master, Workiz a mirror until it is disconnected). Seeded once
 * from the Workiz mirror by `scheduling:seed-technicians`; EzBizi owns it
 * after that. `workiz_member_id` is only how the mirror names the same person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_technicians', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_field_tech')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('workiz_member_id')->nullable()->unique()->constrained('workiz_team_members')->nullOnDelete();
            $table->timestamps();
            $table->index(['franchise_id', 'is_active']);
        });

        Schema::create('scheduling_technician_areas', function (Blueprint $table) {
            $table->foreignUuid('technician_id')->constrained('scheduling_technicians')->cascadeOnDelete();
            $table->foreignUuid('service_area_id')->constrained()->cascadeOnDelete();
            $table->primary(['technician_id', 'service_area_id']);
        });

        Schema::create('job_technicians', function (Blueprint $table) {
            $table->foreignUuid('job_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('technician_id')->constrained('scheduling_technicians')->cascadeOnDelete();
            // ezbizi: a person here decided it; workiz: imported, and an
            // import may replace it. An import never touches an ezbizi row.
            $table->string('source', 10)->default('ezbizi');
            $table->timestamps();
            $table->primary(['job_id', 'technician_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_technicians');
        Schema::dropIfExists('scheduling_technician_areas');
        Schema::dropIfExists('scheduling_technicians');
    }
};
