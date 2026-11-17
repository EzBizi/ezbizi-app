<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter P1a (2026-11-17).
 *
 * `name`: the internal campaign name, distinct from the subject. Null on
 * every existing row; the screens show the subject until one is typed, so
 * nothing is rewritten.
 *
 * `preheader`: the line an inbox shows after the subject, rendered as hidden
 * text at the top of the email in both block and custom-HTML modes.
 *
 * `newsletter_revisions`: one row per successful save, so an earlier version
 * can be previewed and restored (a restore is itself a new revision, so the
 * newer work stays recoverable). The newest 50 per newsletter are kept, plus
 * every row marked as a restore point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->string('name')->nullable()->after('subject');
            $table->string('preheader')->nullable()->after('name');
        });

        Schema::create('newsletter_revisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('newsletter_id');
            $table->unsignedInteger('revision');
            $table->json('blocks')->nullable();
            $table->longText('html_override')->nullable();
            $table->string('subject')->nullable();
            $table->string('name')->nullable();
            $table->string('preheader')->nullable();
            $table->uuid('author_id')->nullable();
            $table->boolean('restore_point')->default(false);
            $table->unsignedInteger('restored_from')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['newsletter_id', 'revision']);
            $table->index('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('newsletter_id')->references('id')->on('newsletters')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_revisions');
        Schema::table('newsletters', function (Blueprint $table) {
            $table->dropColumn(['name', 'preheader']);
        });
    }
};
