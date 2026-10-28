<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email filters (Jose, 2026-10-28: "On Emails people should be allowed to
 * create filter, as well as being allowed to make filters for everyone").
 *
 * A filter is personal (user_id) or shared over a place (scope_type/scope_id:
 * organization, company or franchise), never both. It matches on the sender
 * and/or subject and moves matching mail to the Notifications tab or hides it
 * from the inbox list in EzBizi. Gmail itself is not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_filters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id')->nullable();
            $table->string('scope_type')->nullable();
            $table->uuid('scope_id')->nullable();
            $table->string('name');
            $table->string('from_contains')->nullable();
            $table->string('subject_contains')->nullable();
            $table->string('action');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_filters');
    }
};
