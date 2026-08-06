<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Give Laravel's queue table its own name, and the domain word back.
 *
 * A field-service CRM's central entity is a **job**, and that is the word the
 * owner and every future developer will use. Laravel's default queue table is
 * also called `jobs`, so one of them has to move — and it should be the one
 * nobody reads.
 *
 * The alternative was naming the business table `service_jobs`, which costs one
 * line today and a small confusion in every conversation afterwards.
 *
 * Safe to run: the queue table is empty, there are no workers, and
 * config/queue.php reads the name from config rather than assuming it. The
 * `payload` check makes this idempotent in the only way that matters — it can
 * never rename the business table by mistake, whatever order things run in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs')
            && Schema::hasColumn('jobs', 'payload')
            && ! Schema::hasTable('queue_jobs')) {
            Schema::rename('jobs', 'queue_jobs');
        }
    }

    public function down(): void
    {
        // Only if the name is free again — otherwise rolling back would collide
        // with the business table, which is a far worse outcome than a queue
        // table left under its new name.
        if (Schema::hasTable('queue_jobs') && ! Schema::hasTable('jobs')) {
            Schema::rename('queue_jobs', 'jobs');
        }
    }
};
