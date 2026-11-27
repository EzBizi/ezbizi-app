<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-role phone bottom bar (Jose, 2026-11-23): up to four
 * {module, tab|null} slots; "More" is the client's fixed fifth.
 * NULL means "not set": the client draws its own default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('mobile_bar')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('mobile_bar');
        });
    }
};
