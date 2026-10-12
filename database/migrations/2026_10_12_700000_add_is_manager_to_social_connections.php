<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a Google Ads account is a manager (MCC), as Google says
 * (customer.manager). The Ads report rolls a manager up and colours it apart
 * (Jose, 2026-10-12); guessing it from login_customer_id missed every manager
 * nothing was read through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', fn (Blueprint $table) => $table->boolean('is_manager')->default(false)->after('login_customer_id'));
    }

    public function down(): void
    {
        Schema::table('social_connections', fn (Blueprint $table) => $table->dropColumn('is_manager'));
    }
};
