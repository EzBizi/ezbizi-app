<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role whose holders are not named as viewing a lead (Mia, 2026-10-02):
 * marketing reads leads to inspect them, and seeing "Rodrigo is viewing" told
 * the dispatchers somebody else was answering. False keeps everybody visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('hidden_on_leads')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('hidden_on_leads'));
    }
};
