<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Management's own facts about a job (Jose, 2026-10-22): why it waits
 * for review (a word from jobs.review_reasons), the outside company doing it
 * (null: our own team), and whether it needs a second visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('review_reason', 100)->nullable()->after('tags');
            $table->string('external_company', 150)->nullable()->after('review_reason');
            $table->boolean('needs_second_visit')->default(false)->after('external_company');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['review_reason', 'external_company', 'needs_second_visit']);
        });
    }
};
