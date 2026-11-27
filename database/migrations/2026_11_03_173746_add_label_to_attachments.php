<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which side of the work a photo shows: 'before' or 'after' (Inspection sets it
 * on submit; Jose's before & after spec, 2026-11-03). Null for everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('label', 20)->nullable()->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
