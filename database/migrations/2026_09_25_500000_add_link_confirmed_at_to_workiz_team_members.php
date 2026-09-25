<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a person decided which EzBizi user a Workiz technician is — including
 * "nobody". Without it an unlink looks like a row nobody has touched, and the
 * next team sync proposes the same email match again, reverting the person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workiz_team_members', function (Blueprint $table) {
            $table->timestamp('link_confirmed_at')->nullable()->after('auto_matched');
        });
    }

    public function down(): void
    {
        Schema::table('workiz_team_members', function (Blueprint $table) {
            $table->dropColumn('link_confirmed_at');
        });
    }
};
