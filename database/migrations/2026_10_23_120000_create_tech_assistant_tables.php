<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Chimney Tech Assistant (2026-10-23, approved mockup
 * tech-assistant.html): what a part is and where that came from, the manuals
 * it is read from, each tech's open job, and every answer given - so a wrong
 * answer can be marked and corrected.
 *
 * Organization-wide: one WhatsApp line, one parts list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 80);
            $table->string('model', 120);
            $table->json('model_aliases')->nullable();
            $table->string('part_name', 120);
            $table->json('part_aliases')->nullable();
            $table->string('part_number', 60)->nullable();
            // NG, LP or both.
            $table->string('fuel', 8)->default('both');
            $table->string('serial_from', 60)->nullable();
            $table->string('serial_to', 60)->nullable();
            $table->string('status', 20)->default('unverified');
            $table->string('superseded_by', 60)->nullable();
            $table->string('source_type', 20)->default('manual');
            $table->string('source_ref', 200)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'brand', 'model']);
            $table->index(['organization_id', 'part_number']);
        });

        Schema::create('assistant_manuals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('brand', 80);
            $table->string('models', 255);
            $table->string('title', 160);
            $table->string('revision', 40)->nullable();
            $table->string('path', 255);
            $table->unsignedInteger('page_count')->default(0);
            // One string per page, page 1 first.
            $table->json('pages')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'brand']);
        });

        Schema::create('assistant_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16)->default('whatsapp');
            $table->string('brand', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial', 60)->nullable();
            $table->string('fuel', 8)->nullable();
            $table->string('ignition', 60)->nullable();
            $table->json('parts_discussed')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'closed_at']);
        });

        Schema::create('assistant_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('job_id')->nullable();
            $table->string('channel', 16);
            $table->text('question');
            $table->text('answer');
            // ready, not_yet, or null when the answer was not about ordering.
            $table->string('order_check', 10)->nullable();
            $table->string('confidence', 20)->nullable();
            $table->json('part_ids')->nullable();
            $table->json('manual_pages')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->string('review', 16)->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('correction')->nullable();
            $table->uuid('correction_entry_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_answers');
        Schema::dropIfExists('assistant_jobs');
        Schema::dropIfExists('assistant_manuals');
        Schema::dropIfExists('assistant_parts');
    }
};
