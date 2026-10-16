<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Grok call, kept a month so a wrong answer can be looked at, and the
 * ones a person pinned with the right answer, replayed by ezbizi:ai-eval when
 * a prompt changes (A1 Leads takeover item, Jose 2026-10-16 "go ahead").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_traces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 120);              // the class that asked
            // Whose data the prompt carried, where the caller knows (a lead, the
            // asking user): an access or erasure request finds the trace.
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->longText('prompt');
            $table->json('schema');
            $table->json('response')->nullable();       // null: it failed
            $table->string('error', 400)->nullable();
            $table->unsignedInteger('ms');
            $table->timestamp('created_at')->index();
            $table->index(['scope', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ai_eval_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 120)->index();
            $table->longText('prompt');
            $table->json('schema');
            $table->json('expected');                   // set by a person, not copied from the AI
            $table->text('notes')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_eval_cases');
        Schema::dropIfExists('ai_traces');
    }
};
