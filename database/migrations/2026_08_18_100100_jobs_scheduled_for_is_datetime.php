<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scheduled_for` is wall time, not a UTC instant (TODO #20).
 *
 * MySQL TIMESTAMP converts with the session timezone. DATETIME stores the
 * clock the technician named.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE jobs MODIFY scheduled_for DATETIME NULL');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE jobs MODIFY scheduled_for TIMESTAMP NULL');
        }
    }
};
