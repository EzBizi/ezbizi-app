<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company address typed on one onboarding, which the contract prints in
 * place of the company's (Jose, 2026-10-05: "we have not set all company
 * addreses yet ... if it's typed it overrides whatever is in place"). Null
 * is the company's or the organization's own, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->string('employer_address', 500)->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', fn (Blueprint $table) => $table->dropColumn('employer_address'));
    }
};
