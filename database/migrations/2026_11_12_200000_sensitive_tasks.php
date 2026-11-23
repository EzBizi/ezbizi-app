<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sensitive tasks (Jose, 2026-11-12: "a safety filter in our task board which
 * required the authenticator to see"). `tasks.sensitive` marks one; its file
 * names move into `attachments.sealed_name` (encrypted, too long for the
 * 255-character original_name) and original_name says only "Locked file".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('sensitive')->default(false)->after('title');
        });
        Schema::table('attachments', function (Blueprint $table) {
            $table->text('sealed_name')->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('sealed_name');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('sensitive');
        });
    }
};
