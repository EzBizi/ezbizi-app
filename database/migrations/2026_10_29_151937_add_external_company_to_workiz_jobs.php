<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The external company (sub-contractor) a Workiz job was sent by. Workiz's own
 * Sources report credits the job to it (measured on Ohio, September 2026:
 * ChimCare 23, MS Chimney 29), and neither API carries it: only the web job
 * read does (`company_id`). Read by ezbizi:workiz-web-job-companies; the
 * sync never writes these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->string('external_company_id', 20)->nullable()->after('job_source');
            $table->timestamp('external_company_read_at')->nullable()->after('external_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('workiz_jobs', function (Blueprint $table) {
            $table->dropColumn(['external_company_id', 'external_company_read_at']);
        });
    }
};
