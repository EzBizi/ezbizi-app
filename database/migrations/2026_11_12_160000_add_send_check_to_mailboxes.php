<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Can send", checked without sending (Jose 11-12): when it was last checked
 * and, if it failed, why. Null `send_checked_at` means due now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->timestamp('send_checked_at')->nullable();
            $table->text('send_problem')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['send_checked_at', 'send_problem']);
        });
    }
};
