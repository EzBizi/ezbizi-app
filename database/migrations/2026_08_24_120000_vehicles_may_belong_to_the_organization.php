<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A van can sit on the organization itself, not only on a company.
 *
 * Empty `company_id` is the group register — visible throughout the tenant,
 * written by people who act at the organization. Franchise tags still need a
 * company; an organization vehicle cannot carry one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->change();
            $table->foreign('company_id')
                ->references('id')->on('companies')->restrictOnDelete();
        });
    }
};
