<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Design 178 (Jose, 2026-11-03): Timeline and Formula columns. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_columns', function (Blueprint $table) {
            $table->enum('type', ['text', 'number', 'status', 'date', 'person', 'dropdown', 'checkbox', 'timeline', 'formula'])->change();
        });
    }

    public function down(): void {}
};
