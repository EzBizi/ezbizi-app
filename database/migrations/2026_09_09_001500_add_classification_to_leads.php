<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the enquiry looks like, read by a model: BOOK, SUPPORT or ARCHIVE.
 *
 * Item 5 of the a1tools list (Jose, 2026-09-08, "as you recommend"). a1leads
 * sorts every lead into those three the moment it has text; this is the same
 * three words, stored beside our own `status` and never confused with it -
 * `classification` is what the lead LOOKS LIKE, `status` is what somebody
 * DECIDED. The one-line summary goes in the existing `summary` column, which
 * was declared for exactly this the day the table was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('classification', 16)->nullable()->after('status');
            $table->timestamp('classified_at')->nullable()->after('classification');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['classification', 'classified_at']);
        });
    }
};
