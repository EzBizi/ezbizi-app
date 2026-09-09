<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manager a Google Ads account is read THROUGH.
 *
 * listAccessibleCustomers returns the accounts the signed-in user reaches
 * directly: twenty-two for A-1 Chimney. Google's own client tree under the
 * Ads Manager (4051729356) lists thirty-six enabled accounts. The other
 * twenty-three - Stamford, Hamden, Providence, Seattle, St Louis and the
 * rest - are reachable only with `login-customer-id` set to the manager, and
 * every read of them without it answers PERMISSION_DENIED. They were never
 * connected, so their leads never arrived. Jose, 2026-09-09: "we cant miss a
 * single one". Measured the same day.
 *
 * Null means "the account is its own login customer", which is what every
 * connection made before today is. The comment in LocalServicesLeads that
 * said manager-mediated access "belongs in a column on the connection" was
 * right; this is the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->string('login_customer_id', 20)->nullable()->after('remote_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropColumn('login_customer_id');
        });
    }
};
