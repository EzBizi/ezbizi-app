<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "An earlier opening is available" texts (Shoval, 2026-10-26). One per job and open day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_reschedule_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('service_area_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->date('open_date');
            $table->dateTime('booked_for');
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['job_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_reschedule_offers');
    }
};
