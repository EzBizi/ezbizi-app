<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jose, 2026-11-13: Monday's People column, several people per cell (TaskColumn::TYPES). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_columns', function (Blueprint $table) {
            $table->enum('type', ['text', 'number', 'status', 'date', 'person', 'dropdown', 'checkbox', 'timeline', 'formula',
                'long_text', 'currency', 'phone', 'email', 'url', 'datetime', 'tags', 'link', 'people'])->change();
        });
    }

    public function down(): void {}
};
