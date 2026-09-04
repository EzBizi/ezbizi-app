<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter — the mailing, who it goes to, who left, and what the provider said.
 *
 * Every table is XOR-scoped to organization / company / franchise, the same
 * shape `social_connections` and mailboxes use (§3). A newsletter belongs to the
 * place it was filed from, and a sibling company never sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_audiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->string('name');

            /*
             * Where the addresses come from, and the two are genuinely
             * different things rather than one with a flag.
             *
             *   customers — derived from EzBizi's own customer book at send
             *               time, minus anybody who unsubscribed. We hold the
             *               addresses and the consent question is ours.
             *   mailchimp — a list that lives in the tenant's Mailchimp. We
             *               hold an id and nothing else: no addresses, no
             *               consent records, no erasure surface.
             */
            $table->enum('kind', ['customers', 'mailchimp']);
            $table->string('mailchimp_list_id')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('newsletters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->uuid('newsletter_audience_id')->nullable();
            $table->uuid('created_by')->nullable();

            $table->string('subject');

            /*
             * The blocks, as the editor holds them. The rendered HTML is NOT
             * stored beside them as a cache — it is derived on the way out, so
             * the two can never disagree about what the mailing says.
             *
             * `html_override` is the exception and it is deliberate: once
             * somebody hand-edits a block's markup in HTML mode, rebuilding it
             * from fields would silently throw their edit away. A block that
             * has been hand-edited keeps its HTML and says so on screen.
             */
            $table->json('blocks');
            $table->longText('html_override')->nullable();

            $table->string('status')->default('draft');

            // Wall clock plus the clock it belongs to, per TODO #20 — the same
            // pair scheduled social posts use, and for the same reason: the
            // promise was about a clock on a wall, not a moment in physics.
            $table->string('scheduled_for')->nullable();
            $table->string('scheduled_timezone')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->index(['status']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('newsletter_audience_id')->references('id')
                ->on('newsletter_audiences')->nullOnDelete();
        });

        /*
         * Who asked to stop, and WHERE.
         *
         * Scoped, never global. Leaving A-1 Chimney's list must not remove
         * somebody from Premier Garage's — they are different businesses who
         * happen to share a customer, and one tenant must never be able to
         * learn who unsubscribed from another.
         *
         * A row here outlives the audience it was asked from: the whole point
         * is that deleting a list and rebuilding it does not resurrect the
         * people who left.
         */
        Schema::create('newsletter_unsubscribes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->string('email');
            $table->timestamp('unsubscribed_at');
            $table->timestamps();

            $table->unique(['organization_id', 'scope_type', 'scope_id', 'email'], 'newsletter_unsub_unique');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        /*
         * One row per SEND, not per recipient.
         *
         * That is the difference from `social_post_targets`, where EzBizi
         * genuinely delivers once per account and owns each outcome. Here the
         * tenant's provider owns per-recipient delivery, so a row per address
         * would be a copy of their data that goes stale the moment anybody
         * bounces — and inventing it would also be inventing the tracking
         * surface §8 of the design says nobody has decided on.
         */
        Schema::create('newsletter_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('newsletter_id');

            $table->string('provider');
            $table->string('remote_id')->nullable();
            $table->unsignedInteger('recipients')->default(0);

            $table->string('status')->default('queued');
            $table->text('failure')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('newsletter_id')->references('id')->on('newsletters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_sends');
        Schema::dropIfExists('newsletter_unsubscribes');
        Schema::dropIfExists('newsletters');
        Schema::dropIfExists('newsletter_audiences');
    }
};
