<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own lists for four more website widgets: FAQs, services,
 * before-and-after pairs and hand-picked reviews.
 *
 * Jose, 2026-09-25: "yes all of it" — A1 Tools' Services, Reviews, Before &
 * After and FAQ widgets too, although no page uses them today. One table,
 * told apart by `kind`, because the four are the same shape (a title, some
 * words, a picture or two, a link) and four tables would be four copies of
 * one editor, one endpoint and one payload key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('organization_id', 36);
            $table->char('company_id', 36);
            // faq | service | before_after | review
            $table->string('kind', 20);

            // A question, a service's name, a project's title, a reviewer's name.
            $table->string('title', 191);
            // An answer, a description, a review's words.
            $table->text('body')->nullable();
            // A location, a job title, a review's source.
            $table->string('subtitle', 191)->nullable();
            $table->string('image_url', 1000)->nullable();
            // The AFTER picture of a before-and-after pair.
            $table->string('image2_url', 1000)->nullable();
            $table->string('link_url', 1000)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('category', 191)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->date('dated')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'kind', 'sort_order']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_items');
    }
};
