<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta's instant-form leads alone, beside its "lead" total (which counts the
 * website pixel too), for the report texts' "how many forms" (Jose, 2026-11-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_insights', function (Blueprint $table) {
            $table->unsignedInteger('forms')->default(0)->after('leads');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_insights', function (Blueprint $table) {
            $table->dropColumn('forms');
        });
    }
};
