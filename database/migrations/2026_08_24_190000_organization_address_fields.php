<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business address as the same five columns a company and a customer have.
 *
 * `organizations.address` stays: it is the formatted line contracts merge as
 * {{organization.address}}. The parts are what somebody edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('address_line1', 200)->nullable()->after('address');
            $table->string('address_line2', 200)->nullable()->after('address_line1');
            $table->string('city', 100)->nullable()->after('address_line2');
            $table->string('state', 2)->nullable()->after('city');
            $table->string('postal_code', 12)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'address_line1', 'address_line2', 'city', 'state', 'postal_code',
            ]);
        });
    }
};
