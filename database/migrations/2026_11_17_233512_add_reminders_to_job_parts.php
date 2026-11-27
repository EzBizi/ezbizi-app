<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part reminders (Jose, 2026-11-17): "be able to set reminders manually as
 * well on the board per item", and a notification when a part is three days
 * late. `remind_at`/`remind_note` are the manual one; `reminded_at` and
 * `late_notified_at` record that each was sent, so it is sent once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_parts', function (Blueprint $table) {
            $table->timestamp('remind_at')->nullable()->after('received_at');
            $table->string('remind_note', 160)->nullable()->after('remind_at');
            $table->timestamp('reminded_at')->nullable()->after('remind_note');
            $table->timestamp('late_notified_at')->nullable()->after('reminded_at');
            $table->index('remind_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_parts', function (Blueprint $table) {
            $table->dropIndex(['remind_at']);
            $table->dropColumn(['remind_at', 'remind_note', 'reminded_at', 'late_notified_at']);
        });
    }
};
