<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation that carries paperwork is held until the employer signs it
 * (Jose, 2026-10-06: "make it so the onboarding gets sent only after the
 * employer signs"). Null is sent, as every invitation before this was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->timestamp('held_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('held_at'));
    }
};
