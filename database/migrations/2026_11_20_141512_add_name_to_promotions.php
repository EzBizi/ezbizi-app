<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promotion's name is its Leads tab (Shoval, 2026-11-06: "Create under
 * leads new tab 'Returning Client Promo'"): the answers to a named promotion
 * land in a Leads tab of that name. Unnamed promotions keep landing in
 * Leads › Promotions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('name', 60)->nullable()->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
