<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions, round two (Jose, 2026-11-09): the list is filtered and ticked
 * by hand, so a promotion keeps the phones it was sent to rather than
 * re-deriving them, and may carry one picture (MMS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->json('phones')->nullable()->after('franchise_ids');
            $table->string('media_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['phones', 'media_path']);
        });
    }
};
