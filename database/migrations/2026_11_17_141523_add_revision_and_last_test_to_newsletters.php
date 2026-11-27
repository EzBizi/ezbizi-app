<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter redesign stage 1 (2026-11-17).
 *
 * `revision`: bumped on every saved edit, so a stale tab's save is refused
 * (409) instead of overwriting newer work, and preview/preflight/test say
 * which version they describe.
 *
 * `last_test_*`: the most recent test send, which revision it carried, and
 * what Mailchimp said ("accepted": it took the request, not "delivered").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('last_test_revision')->nullable();
            $table->string('last_test_to')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_result', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->dropColumn(['revision', 'last_test_revision', 'last_test_to', 'last_test_at', 'last_test_result']);
        });
    }
};
