<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Row 43 (Sheryl, 11-23): an update's author edits or deletes it, as on Monday. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_updates', function (Blueprint $t) {
            $t->timestamp('edited_at')->nullable()->after('mentions');
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('task_updates', function (Blueprint $t) {
            $t->dropColumn('edited_at');
            $t->dropSoftDeletes();
        });
    }
};
