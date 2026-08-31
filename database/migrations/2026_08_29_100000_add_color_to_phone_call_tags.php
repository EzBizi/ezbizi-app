<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A colour for a call tag.
 *
 * Tags are read at a glance — down a list of calls, or as slices of a report.
 * Five identical grey chips take as long to read as the words themselves, and
 * "Complaint" being the same weight as "Booked" is the sort of thing that gets
 * scanned past. Colour is what makes a tag legible at the speed people
 * actually read a call list.
 *
 * Stored as a hex string, matching what Tasks already does for board colours —
 * one convention for a user-chosen colour rather than a second one. Nullable,
 * because an untinted tag is a valid choice and every existing tag has to
 * remain one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_call_tags', function (Blueprint $table) {
            $table->string('color', 32)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('phone_call_tags', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
