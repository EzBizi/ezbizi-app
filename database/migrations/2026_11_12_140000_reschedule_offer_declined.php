<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the customer replied NO to an offer: it keeps their time and closes the offer (2026-11-12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduling_reschedule_offers', function (Blueprint $table) {
            $table->timestamp('declined_at')->nullable()->after('moved_at');
        });
    }

    public function down(): void
    {
        Schema::table('scheduling_reschedule_offers', function (Blueprint $table) {
            $table->dropColumn('declined_at');
        });
    }
};
