<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When `lead.follow_up_due` was raised for this lead - the once-guard: a lead
 * is followed up at most once, ever (`ezbizi:leads-follow-up-due`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('follow_up_raised_at')->nullable()->after('reply_overdue_raised_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('follow_up_raised_at');
        });
    }
};
