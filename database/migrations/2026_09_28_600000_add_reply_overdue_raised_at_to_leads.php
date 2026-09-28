<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When `lead.reply_overdue` was last raised for this lead, so it is raised once
 * per customer message left waiting, not once a minute
 * (`ezbizi:leads-reply-overdue`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('reply_overdue_raised_at')->nullable()->after('last_contact_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('reply_overdue_raised_at');
        });
    }
};
