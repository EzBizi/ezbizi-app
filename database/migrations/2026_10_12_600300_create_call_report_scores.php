<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI's coaching read of each answered call in the Performance report (Jose,
 * 2026-10-12: "see their tactics, and see why some may have low conversion").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_report_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('call_id', 64);            // RingCentral's call-log id
            $table->unsignedTinyInteger('score')->nullable(); // 1-10; null: no dispatcher spoke
            $table->json('checks')->nullable();
            $table->text('strength')->nullable();
            $table->text('improve')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_report_scores');
    }
};
