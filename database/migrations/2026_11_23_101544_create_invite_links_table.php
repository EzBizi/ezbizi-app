<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shareable link that many people use to make their own accounts, each in
 * the role and place the link was made for (Jose, 2026-11-23: "send an invite
 * link in which they can create their accounts on their own").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->uuid('assigned_organization_id')->nullable();
            $table->foreignUuid('assigned_company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('assigned_franchise_id')->nullable()->constrained('franchises')->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignUuid('invite_link_id')->nullable()->after('role_id')
                ->constrained('invite_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invite_link_id');
        });
        Schema::dropIfExists('invite_links');
    }
};
