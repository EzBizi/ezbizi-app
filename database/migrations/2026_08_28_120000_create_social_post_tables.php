<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was composed, and what happened to it at each account it was sent to.
 *
 * ── Why two tables and not one ───────────────────────────────────
 *
 * One post goes to several accounts and each send succeeds or fails on its own:
 * Pinterest takes the image, TikTok is still processing it, LinkedIn refused
 * because the token expired this morning. A single row would have to hold one
 * status for all of them, so the first partial failure would either be reported
 * as a whole-post failure — untrue, and it would invite somebody to press
 * publish again on the accounts that already have it — or be lost.
 *
 * The words and the file live on the post because they are the same for every
 * target; a copy per target is how two accounts end up with a body that was
 * edited between sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('created_by')->nullable();

            $table->text('body')->nullable();
            $table->string('title')->nullable();

            /*
             * The disk is a column, not a config read, for the reason
             * FileStore's docblock gives: changing FILESYSTEM_DISK must not make
             * yesterday's file unreadable. The MIME type is not a column —
             * `PostPayload` derives both kind and MIME from the extension the
             * stored path keeps, and a second copy of that answer would be free
             * to disagree with the bytes.
             */
            $table->string('media_path')->nullable();
            $table->string('media_disk')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'created_at']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('social_post_id');
            $table->uuid('social_connection_id');

            $table->string('status')->default('queued');
            $table->string('remote_id')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('social_post_id')->references('id')->on('social_posts')->cascadeOnDelete();

            /*
             * Disconnecting an account takes its sends with it. The alternative
             * — keeping the rows and nulling the connection — leaves a queue
             * full of entries that name nothing, and the audit log already
             * records that somebody disconnected it.
             */
            $table->foreign('social_connection_id')->references('id')->on('social_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_posts');
    }
};
