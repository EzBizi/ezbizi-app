<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's home address in parts (Jose, 2026-09-30): "a full field address
 * that works as a lookup and then the Main Address - Unit - City - State -
 * Country - Zip", on Your account and on their Human Resources record.
 *
 * `address` stays: it is the one line contracts and onboarding print, and it
 * is composed from these parts whenever they are saved. Nothing is copied
 * here from it. A row saved before today still reads, through
 * User::addressParts(), and gets its parts the first time it is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('address_line1', 191)->nullable()->after('address');
            $table->string('address_line2', 191)->nullable()->after('address_line1');
            $table->string('city', 100)->nullable()->after('address_line2');
            $table->string('state', 64)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state');
            $table->string('country', 100)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']);
        });
    }
};
