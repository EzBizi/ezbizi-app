<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One AI label per call per provider, so Claude's and Grok's answers on the
 * same call can be compared (Jose, 2026-10-06). The label is a word from a
 * fixed list (CallLabels); no words of the call are kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_report_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('call_id', 64);  // RingCentral's call-log id
            $table->string('provider', 16); // claude | grok
            $table->string('label', 32)->nullable(); // null when it gave none
            $table->timestamps();

            $table->unique(['organization_id', 'call_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_report_labels');
    }
};
