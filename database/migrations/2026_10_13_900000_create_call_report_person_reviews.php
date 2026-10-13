<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grok's daily and weekly read of each employee's scored calls, for the
 * Scoring tab (Jose, 2026-10-13: "Daily and Weekly").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_report_person_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('person', 191);          // answered_by, as the report names them
            $table->string('period', 8);            // daily | weekly
            $table->date('from');
            $table->date('to');
            $table->unsignedSmallInteger('calls');
            $table->decimal('avg_score', 4, 2)->nullable();
            $table->text('summary');
            $table->json('strengths');
            $table->json('work_on');
            $table->string('trend', 8)->nullable(); // up | down | flat
            $table->timestamps();
            $table->unique(['organization_id', 'person', 'period', 'from'], 'person_reviews_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_report_person_reviews');
    }
};
