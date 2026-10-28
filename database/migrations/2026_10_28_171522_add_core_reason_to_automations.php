<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            // What stops working when this rule is off; set by the module whose tab depends on it.
            $table->string('core_reason', 500)->nullable()->after('disabled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('automations', fn (Blueprint $table) => $table->dropColumn('core_reason'));
    }
};
