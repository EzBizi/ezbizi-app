<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat can be about a job (EzChat design 213: the "Jobs" chip and the
 * "JOB-05112 · Hartford" line). Nullable, and nulled if the job goes: the
 * conversation outlives the record it was about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignUuid('job_id')->nullable()->after('direct_key')->constrained('jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_id');
        });
    }
};
