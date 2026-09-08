<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote the note on a lead, and when.
 *
 * UI/UX, 2026-09-08, on Jose's delegation of the note question: a shared
 * text column that reads "Called back, no answer" cannot say who or when,
 * and two dispatchers overwrite each other silently. A field that cannot
 * attribute itself is one people stop trusting. The row renders
 * "Maya · 2h ago" beside the text from these two columns.
 *
 * A new file rather than an edit to 2026_09_08_233000, which may already
 * have run on the box by the time this lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignUuid('note_by_user_id')
                ->nullable()
                ->after('note')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('note_updated_at')->nullable()->after('note_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('note_by_user_id');
            $table->dropColumn('note_updated_at');
        });
    }
};
