<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an estimate is for, in words. A1 Tools titled 2,381 of the 2,383
 * estimates it hands over, and only 12 of them have lines to say it instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->string('title', 191)->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
