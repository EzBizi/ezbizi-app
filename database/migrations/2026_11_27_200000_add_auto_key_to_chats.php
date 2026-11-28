<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic groups (Jose, 11-27): "Dispatchers" and "Technicians" per company,
 * whose members follow the role. `auto_key` names which one ("dispatchers:{company}");
 * null on every chat a person made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('auto_key', 80)->nullable()->after('direct_key');
            $table->unique(['organization_id', 'auto_key']);
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'auto_key']);
            $table->dropColumn('auto_key');
        });
    }
};
