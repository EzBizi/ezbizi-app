<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which roles a role's holders may offer when they invite or re-role somebody
 * (Jose, 2026-10-01, on Eve's Suggestion: "this shold be doable on roles and
 * permissions to select what roles they can offer"). Null keeps the rule that
 * was: any role reaching no further than your own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('invitable_role_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('invitable_role_ids'));
    }
};
