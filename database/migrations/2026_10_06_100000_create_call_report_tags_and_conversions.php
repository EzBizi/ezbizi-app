<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two hand-set facts the Experimental call report cannot read off the phones
 * (Jose, 2026-10-05):
 *
 *   - which client a dispatcher answers for, New or Existing, set per person;
 *   - a call somebody marked as booked by hand ("Convert to booked"), with who
 *     and when, which is the conversion history.
 *
 * People are keyed by the NAME the report shows: RingCentral and Workiz both
 * speak names, not EzBizi users, and the report canonicalises them already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_report_dispatcher_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('person', 191);
            $table->string('tag', 16); // new | existing
            $table->timestamps();

            $table->unique(['organization_id', 'person']);
        });

        Schema::create('call_report_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // RingCentral's call-log id. Not a foreign key: the call lives there.
            $table->string('call_id', 64);
            $table->timestamp('call_started_at')->nullable();
            $table->foreignUuid('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_report_conversions');
        Schema::dropIfExists('call_report_dispatcher_tags');
    }
};
