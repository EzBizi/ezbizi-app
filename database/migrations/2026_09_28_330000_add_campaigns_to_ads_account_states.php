<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each account's Local Services campaigns as last seen by the hourly watch
 * (`ezbizi:ads-campaign-watch`): id, name, status and Google's serving verdict,
 * so a campaign that stops showing can be told apart from one that never did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads_account_states', function (Blueprint $table) {
            $table->json('campaigns')->nullable()->after('certification_failed');
            $table->timestamp('campaigns_checked_at')->nullable()->after('campaigns');
        });
    }

    public function down(): void
    {
        Schema::table('ads_account_states', function (Blueprint $table) {
            $table->dropColumn(['campaigns', 'campaigns_checked_at']);
        });
    }
};
