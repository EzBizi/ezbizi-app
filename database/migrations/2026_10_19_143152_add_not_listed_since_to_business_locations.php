<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Google's complete list of an account's locations stopped including
 * one (A1 Leads takeover, 2026-10-19). "Not listed", never "removed": a
 * profile can drop out of an account because access moved, not because it
 * is gone. Null while it is listed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->timestamp('not_listed_since')->nullable()->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn('not_listed_since');
        });
    }
};
