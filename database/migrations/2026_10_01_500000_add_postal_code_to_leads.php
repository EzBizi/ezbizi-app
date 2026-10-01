<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ZIP an enquirer gave (Jose, 2026-10-01: the website chat asks for it).
 * Read by Leads for the service-area check when no message mentions one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('postal_code', 10)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('postal_code');
        });
    }
};
