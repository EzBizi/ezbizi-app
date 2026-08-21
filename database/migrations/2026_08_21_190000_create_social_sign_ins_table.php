<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_sign_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('provider');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');
            $table->char('state_hash', 64)->unique();
            $table->text('code_verifier')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('expires_at');
        });

        Schema::dropIfExists('pinterest_sign_ins');
    }

    public function down(): void
    {
        Schema::dropIfExists('social_sign_ins');
    }
};
