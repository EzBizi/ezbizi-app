<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews & Complaints (Shoval, 2026-11-20): whether the person is a client, Priority with a due time
 * for BBB / Attorney General / lawsuits, and the words of a complaint that arrived by email or by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('client_status', 12)->nullable()->after('rating'); // client | not_client
            $table->boolean('priority')->default(false)->after('client_status');
            $table->timestamp('due_at')->nullable()->after('priority');
            $table->unsignedTinyInteger('alerted_level')->default(0)->after('due_at');
            $table->string('title')->nullable()->after('alerted_level');
            $table->text('summary')->nullable()->after('title');
            $table->index(['organization_id', 'priority', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'priority', 'due_at']);
            $table->dropColumn(['client_status', 'priority', 'due_at', 'alerted_level', 'title', 'summary']);
        });
    }
};
