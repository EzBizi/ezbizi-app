<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jose, 2026-11-17: notes could not be edited. A team note's author (or an
 * administrator) may now change it; the note then reads "edited".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_notes', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('mentions');
        });
    }

    public function down(): void
    {
        Schema::table('job_notes', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
