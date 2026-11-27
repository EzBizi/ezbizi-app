<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            // Where an approved suggestion is on its way to the app (Jose,
            // 2026-11-17: "so people know their stuff is being implemented"):
            // approved | building | testing | releasing | live. Null until
            // approved, and again if it is denied.
            $table->string('progress', 16)->nullable()->after('status');
            // The release it ships in, e.g. 0.0.0584, once known.
            $table->string('release_version', 32)->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            $table->dropColumn(['progress', 'release_version']);
        });
    }
};
