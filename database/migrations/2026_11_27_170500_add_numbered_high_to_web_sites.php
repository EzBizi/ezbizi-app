<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The highest number ever used per numbered field family ("template",
 * "about_us_field"), so a removed highest number is never handed out again:
 * a live [ezbizi template=3] would silently show the new text (audit 2026-11-27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->json('numbered_high')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn('numbered_high');
        });
    }
};
