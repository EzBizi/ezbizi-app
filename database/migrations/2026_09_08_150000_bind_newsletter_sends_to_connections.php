<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_sends', function (Blueprint $table) {
            // Never infer the account for legacy pending mailings: the sweep refuses them.
            $table->foreignUuid('connection_id')->nullable()->constrained('social_connections')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connection_id');
            $table->dropColumn('started_at');
        });
    }
};
