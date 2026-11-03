<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call's client (New / Existing) or booking (Booked / Not booked) set by
 * hand on the call report (Jose, 2026-11-03, for Trey: "reclassify from
 * existing to new and viceverse as well as to booke from not booked and
 * viceversa"). One row per call and field; deleting it reverts to what the
 * phones and Workiz say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_report_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // RingCentral's call-log id. Not a foreign key: the call lives there.
            $table->string('call_id', 64);
            $table->string('field', 16); // client | booking
            $table->string('value', 16); // new | existing | booked | not_booked
            $table->foreignUuid('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'call_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_report_overrides');
    }
};
