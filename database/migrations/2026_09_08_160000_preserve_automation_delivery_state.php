<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_events', function (Blueprint $table) {
            $table->json('scope_snapshot')->nullable();
            $table->json('version_ids')->nullable();
            $table->timestamp('processing_until')->nullable();
            $table->uuid('processing_token')->nullable();
        });
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->json('actions_snapshot')->nullable();
            $table->json('context_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', fn (Blueprint $table) => $table->dropColumn(['actions_snapshot', 'context_snapshot']));
        Schema::table('automation_events', fn (Blueprint $table) => $table->dropColumn([
            'scope_snapshot', 'version_ids', 'processing_until', 'processing_token',
        ]));
    }
};
