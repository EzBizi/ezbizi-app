<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a lead's job was linked: null when somebody booked it from the lead,
 * 'phone' when `ezbizi:match-leads-to-jobs` found it by the customer's number.
 * A found link is a guess with good odds, and the screen says which it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('job_matched_by', 16)->nullable()->after('job_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('job_matched_by');
        });
    }
};
