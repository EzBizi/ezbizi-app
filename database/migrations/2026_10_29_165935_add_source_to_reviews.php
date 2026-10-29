<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a review was written: Google, or eKomi (A1 Leads takeover #43). Existing rows are Google's. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('source', 20)->default('google')->after('connection_id');
            $table->index(['organization_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
