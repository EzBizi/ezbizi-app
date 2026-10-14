<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much each metric counts in a person's Scoring score, per organization
 * (Jose, 2026-10-14: "a setting to set how important each metric is ... with
 * all summing up to 100%"). No row means the defaults in ScoringWeights.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_scoring_weights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('weights');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_scoring_weights');
    }
};
