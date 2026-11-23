<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter P1b (2026-11-18).
 *
 * `newsletter_brands`: one per scope (organization, company or franchise),
 * the logo, colours, font stack, default button and footer note a newsletter
 * takes when "Apply brand" is pressed.
 *
 * `newsletters.brand`: the brand AS APPLIED to that newsletter, a snapshot,
 * so editing the company brand later never changes a mailing already written
 * or sent. Null on every existing row (no brand, the renderer's defaults).
 *
 * `newsletter_sections` + `newsletter_section_versions`: saved sections. A
 * linked use pins a version; every published change is a new version row, so
 * a pinned (or sent) newsletter keeps rendering what it was built with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');
            $table->json('settings');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'scope_type', 'scope_id'], 'newsletter_brands_scope_unique');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('newsletters', function (Blueprint $table) {
            $table->json('brand')->nullable()->after('html_override');
        });

        Schema::create('newsletter_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');
            $table->string('name');
            $table->json('blocks');
            $table->string('mode', 10)->default('copy');
            $table->unsignedInteger('version')->default(1);
            $table->string('thumbnail_url', 2048)->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('newsletter_section_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('newsletter_section_id');
            $table->unsignedInteger('version');
            $table->json('blocks');
            $table->uuid('updated_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['newsletter_section_id', 'version'], 'newsletter_section_versions_unique');
            $table->index('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('newsletter_section_id')->references('id')->on('newsletter_sections')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_section_versions');
        Schema::dropIfExists('newsletter_sections');
        Schema::table('newsletters', function (Blueprint $table) {
            $table->dropColumn('brand');
        });
        Schema::dropIfExists('newsletter_brands');
    }
};
