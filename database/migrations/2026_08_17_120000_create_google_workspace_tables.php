<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind a Google Workspace domain to one EzBizi organization.
 *
 * Unique on `domain` across the whole table, not per tenant: domain-wide
 * delegation is granted to EzBizi's service account, so the first organization
 * that proves it runs a-1chimney.com is the only one that may impersonate
 * addresses there. A second tenant typing the same domain would otherwise read
 * the first tenant's mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_workspace_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('domain')->unique();
            $table->string('admin_email');
            $table->string('status');
            $table->text('status_reason')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('google_workspace_sign_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('domain');
            $table->char('state_hash', 64)->unique();
            $table->text('code_verifier');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_workspace_sign_ins');
        Schema::dropIfExists('google_workspace_connections');
    }
};
