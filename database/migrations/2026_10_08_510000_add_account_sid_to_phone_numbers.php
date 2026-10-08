<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Twilio account that holds a number, when it is not the one behind the
 * number's connection (Jose, 2026-10-08). A-1 Chimney's approved toll-free
 * +1 855 955 3322 stays on the platform parent account, because a toll-free
 * verification does not survive a move, yet it texts for A-1. Null means the
 * connection's own account, which is every other number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('account_sid', 34)->nullable()->after('twilio_sid');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn('account_sid');
        });
    }
};
