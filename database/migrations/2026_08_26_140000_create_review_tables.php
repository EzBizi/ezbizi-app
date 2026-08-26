<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews left on a connected Business Profile, and what the business said back.
 *
 * Two tables rather than six columns on one, and the reason is ownership: a
 * review is what a member of the public wrote, and a reply is a public
 * statement this organization is about to make in its own name. Mixing them
 * puts "who approved this" on a row that is otherwise somebody else's words,
 * and makes the erasure question harder to answer than it needs to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Filed like a lead — nullable company and franchise, both null
            // meaning organization-wide. The connection's scope is copied down
            // by the sync so AuthorizesCatalogPlace can be reused.
            $table->uuid('company_id')->nullable();
            $table->uuid('franchise_id')->nullable();
            $table->uuid('connection_id')->nullable();

            // Google's full path: accounts/{account}/locations/{loc}/reviews/{id}.
            // Kept whole rather than split, because every v4 call wants it back
            // exactly as given and reassembling it from parts is how a reply
            // ends up posted against the wrong location.
            $table->string('remote_name', 512);
            $table->string('location_name', 512);

            $table->string('reviewer_name')->nullable();
            $table->string('reviewer_photo_url', 1024)->nullable();

            // Nullable: Google occasionally returns a review with no star
            // rating. Defaulting it to 0 or 5 would be inventing an opinion
            // the customer did not express.
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('reviewed_at')->nullable();

            // Bumps when the customer EDITS their review. A reply written
            // against the old wording may no longer make sense, so this is
            // stored rather than folded into reviewed_at.
            $table->timestamp('edited_at')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'remote_name']);
            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'rating']);
            $table->index(['organization_id', 'reviewed_at']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->nullOnDelete();
            $table->foreign('connection_id')->references('id')->on('social_connections')->nullOnDelete();
        });

        Schema::create('review_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // UNIQUE, because Google allows exactly one reply per review and a
            // second call overwrites the first. Modelling it as many would
            // invite a list nobody can publish, and hide which of them is the
            // one actually showing in public.
            $table->uuid('review_id')->unique();

            $table->text('body');

            // draft    — written, waiting for a person
            // published— live on Google, with a time to prove it
            // failed   — Google refused; the text is kept so somebody can fix it
            //
            // There is no `approved` between draft and published. Approval and
            // publication are the same act by the same person in the same
            // request; a stored "approved but not sent" state would be a
            // promise the outbox does not yet exist to keep.
            $table->string('status')->default('draft');

            // Who wrote it — a person, or the drafting job. Kept because "did a
            // human write this or approve a machine's words" is the first
            // question anybody asks about a reply that went wrong.
            $table->string('drafted_by')->default('person');

            $table->uuid('published_by_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('review_id')->references('id')->on('reviews')->cascadeOnDelete();
            $table->foreign('published_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_replies');
        Schema::dropIfExists('reviews');
    }
};
