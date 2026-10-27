<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened a job, on the job (Jose, 2026-10-27: EzBizi the master, Workiz a
 * mirror). The person when EzBizi knows them; always a name: a Workiz
 * dispatcher who has no EzBizi user is still somebody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->uuid('created_by_user_id')->nullable()->after('performed_by_user_id');
            $table->string('created_by_label', 150)->nullable()->after('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['created_by_user_id', 'created_by_label']);
        });
    }
};
