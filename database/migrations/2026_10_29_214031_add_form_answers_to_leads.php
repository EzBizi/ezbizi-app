<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Meta lead-ad form's answers, kept as fields on the lead (UI-UX 160 C1, 2026-10-29). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('form_answers')->nullable();
            $table->string('form_name')->nullable();
            $table->timestamp('form_submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['form_answers', 'form_name', 'form_submitted_at']);
        });
    }
};
