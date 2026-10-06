<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Workiz client's v2 id (CL-…). v2 reads a client, and its notes, only by
 * that id; `client_id` is the number Workiz shows (Customers, 2026-10-06: the
 * client's Workiz notes on the customer page). Filled by
 * ezbizi:sync-workiz-client-ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workiz_clients', function (Blueprint $table) {
            $table->string('v2_id', 64)->nullable()->after('client_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('workiz_clients', function (Blueprint $table) {
            $table->dropIndex(['v2_id']);
            $table->dropColumn('v2_id');
        });
    }
};
