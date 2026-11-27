<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A line can override the call flow (Jose, 2026-11-11): when on, its calls
 * skip hours and the menu and do what `override` says. Kept when switched
 * off, so turning it back on restores what was set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('override_on')->default(false);
            $table->json('override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['override_on', 'override']);
        });
    }
};
