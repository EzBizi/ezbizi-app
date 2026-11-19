<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Desk 11-19 (Jose: "Assigned to me" empty): what kind of event a bell is
// (task.assigned, task.mentioned…), so the bell's tabs can find task bells,
// which carry no automation event.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('kind', 40)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
