<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company an onboarding is under: the employer its contract names, where
 * it is filed (Ana's Suggestion, Jose 2026-10-01: "yes as well as to where
 * the person is assigned"). Null is the organization, as every onboarding
 * before this was, so no contract under way changes its wording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('contract_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', fn (Blueprint $table) => $table->dropColumn('company_id'));
    }
};
