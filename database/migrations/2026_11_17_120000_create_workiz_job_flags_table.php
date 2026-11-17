<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per Workiz job per issue flagged: the dedupe ledger of ezbizi:flag-workiz-jobs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_job_flags', function (Blueprint $table) {
            $table->foreignUuid('workiz_job_id')->constrained('workiz_jobs')->cascadeOnDelete();
            $table->string('issue', 32);
            $table->timestamp('created_at')->nullable();
            $table->primary(['workiz_job_id', 'issue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workiz_job_flags');
    }
};
