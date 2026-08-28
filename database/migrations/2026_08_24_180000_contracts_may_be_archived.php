<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template can be taken off the picker without deleting it.
 *
 * Existing onboardings still pin the version they were started on. New
 * onboardings should not offer a contract that was a mistaken split of
 * another (four Accounting variants where there should be one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
