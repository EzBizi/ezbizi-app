<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A call's recording kept on EzBizi's own disk (Jose, 2026-10-27: every
 * integration removable), so it plays after the account that brought it is
 * gone. See LeadRecordings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->string('recording_path', 255)->nullable()->after('recording_url');
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->dropColumn('recording_path');
        });
    }
};
