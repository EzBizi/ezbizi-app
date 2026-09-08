<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was actually said, per lead.
 *
 * A Local Services lead row is a header: who, which phone number, when, what
 * category. The substance sits in a second Google resource this platform never
 * read — `local_services_lead_conversation` — one row per call or message:
 * which side spoke, when, the call's duration and recording, the message's
 * text. Rodrigo's a1leads pulled it from the start, which is why its list shows
 * "LS Text" and "LS Ads" with the last message under each name and ours showed
 * "unnamed enquiry" with nothing under it.
 *
 * `remote_id` is Google's conversation id, unique per organization: the same
 * account connected twice in one tenant cannot double a call. Cascade with the
 * lead, because a conversation without its lead is a stranger's voice with no
 * enquiry to attach it to.
 *
 * The lead itself gets the four `last_contact_*` columns, denormalised on
 * purpose: the list screen shows a preview line per lead and must not run a
 * subquery per row to find it. They are rewritten from this table on every
 * sync, so they are a cache, never the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('lead_id');

            $table->string('remote_id');

            // Google's own words, stored verbatim: PHONE_CALL, MESSAGE, SMS,
            // WHATSAPP, EMAIL, BOOKING... and CONSUMER or ADVERTISER.
            $table->string('channel', 32);
            $table->string('participant', 32);

            $table->timestamp('occurred_at');

            // Calls.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('recording_url')->nullable();

            // Messages.
            $table->text('text')->nullable();
            $table->json('attachments')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'remote_id']);
            $table->index(['lead_id', 'occurred_at']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            // The rest of what Google says about a lead and we never asked for.
            $table->string('service')->nullable()->after('category');
            $table->string('locale', 16)->nullable()->after('service');
            $table->text('source_note')->nullable()->after('summary');

            // The preview line. See the class comment.
            $table->timestamp('last_contact_at')->nullable()->after('received_at');
            $table->string('last_contact_channel', 32)->nullable()->after('last_contact_at');
            $table->string('last_contact_from', 32)->nullable()->after('last_contact_channel');
            $table->string('last_contact_preview')->nullable()->after('last_contact_from');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'service', 'locale', 'source_note',
                'last_contact_at', 'last_contact_channel', 'last_contact_from', 'last_contact_preview',
            ]);
        });

        Schema::dropIfExists('lead_conversations');
    }
};
