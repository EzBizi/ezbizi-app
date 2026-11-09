<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved views on a Task Board (Jose, 2026-11-09, monday's "Save as new view"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_board_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained('task_boards')->cascadeOnDelete();
            // Null: shared with everyone on the board; else that person's own.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('config');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['board_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_board_views');
    }
};
