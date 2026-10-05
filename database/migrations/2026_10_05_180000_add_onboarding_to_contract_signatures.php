<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The onboarding a signature belongs to, so the employer can sign before the
 * new starter has an account (Jose, 2026-10-05: "she should be able to sign
 * it regardless"). Older rows have none and are found as before, by version
 * and subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->uuid('onboarding_id')->nullable()->after('contract_version_id');
            $table->index('onboarding_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->dropIndex(['onboarding_id']);
            $table->dropColumn('onboarding_id');
        });
    }
};
