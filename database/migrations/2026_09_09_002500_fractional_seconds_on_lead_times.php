<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fractions of a second on the lead clock.
 *
 * Web Management, 2026-09-08: a live chat can hold two messages inside one
 * second, and a second-precision `occurred_at` gives them no order, so a
 * staff reply rendered above the visitor's question. Google already stamps
 * to the microsecond ("13:43:01.118153") and this table was throwing that
 * away. Six digits on the three columns that carry a moment somebody
 * compares; the models write the fraction from now on. Nothing rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->timestamp('occurred_at', 6)->change();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('received_at', 6)->nullable()->change();
            $table->timestamp('last_contact_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->timestamp('occurred_at')->change();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->change();
            $table->timestamp('last_contact_at')->nullable()->change();
        });
    }
};
