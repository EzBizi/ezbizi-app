<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A before/after pair someone cleared for the public website (no faces, no
 * house numbers). Stamped on the AFTER photo, which names its pair; who
 * cleared it is in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->timestamp('web_approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('web_approved_at');
        });
    }
};
