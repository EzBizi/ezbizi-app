<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The service areas a website speaks for (Jose, 2026-10-08): a job's thank-you
 * review link goes to the site of the area the job was in, nj. for New Jersey,
 * cle. for Cleveland, because each has its own Google profile. A franchise is
 * too coarse for that: Ohio is Cleveland, Cincinnati, Columbus and Pittsburgh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->json('service_area_ids')->nullable()->after('franchise_id');
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn('service_area_ids');
        });
    }
};
