<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A branch's own address, printed on its hires' contracts (Merav, 2026-11-19). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
