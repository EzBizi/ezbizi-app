<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who handled the lead's call (A1 Leads takeover, Jose 2026-10-16: "go
 * ahead"): the RingCentral person who answered it, or a person's override.
 * dispatcher_by_user_id is set by an override, and an automatic fill never
 * replaces one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('dispatcher', 120)->nullable()->after('status');
            $table->foreignUuid('dispatcher_by_user_id')->nullable()->after('dispatcher')
                ->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'dispatcher']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'dispatcher']);
            $table->dropConstrainedForeignId('dispatcher_by_user_id');
            $table->dropColumn('dispatcher');
        });
    }
};
