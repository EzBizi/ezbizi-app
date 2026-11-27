<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter P1c (2026-11-18).
 *
 * `newsletter_imports`: the original HTML or SVG somebody imported, kept as
 * uploaded, with what the import made of it. Filed on the view like every
 * newsletter row; `newsletter_id` is null until (unless) it is applied.
 *
 * `newsletter_revisions.label`: the name of a restore point taken on
 * purpose ("Before import"), so history says why it is there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');
            $table->uuid('newsletter_id')->nullable();
            $table->string('kind', 10);
            $table->string('mode', 10)->nullable();
            $table->longText('original');
            $table->json('summary')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('newsletter_id')->references('id')->on('newsletters')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('newsletter_revisions', function (Blueprint $table) {
            $table->string('label', 60)->nullable()->after('restore_point');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_revisions', function (Blueprint $table) {
            $table->dropColumn('label');
        });
        Schema::dropIfExists('newsletter_imports');
    }
};
