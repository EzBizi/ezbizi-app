<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the losing carrier was told, kept so the port can be read back.
 *
 * Jose, 2026-10-05: "transfer number port in ... showing pin codes and
 * verification as well". Encrypted (the model casts it): it holds the
 * carrier account number and the port PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_port_requests', function (Blueprint $table) {
            $table->text('carrier')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('phone_port_requests', function (Blueprint $table) {
            $table->dropColumn('carrier');
        });
    }
};
