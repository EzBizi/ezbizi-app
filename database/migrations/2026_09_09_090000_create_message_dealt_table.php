<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This message has been handled" — one fact for the whole inbox.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHY THIS IS NOT A COLUMN ON `message_seen`.
 *
 *  They look alike and they answer different questions, at
 *  different grains:
 *
 *    message_seen    (mailbox, message, USER)  "Jenny looked at it"
 *    message_dealt   (mailbox, message)        "somebody handled it"
 *
 *  Seen is per person and always will be — it is what lets a shared
 *  inbox say "seen by Jenny" rather than carrying one flag for
 *  everybody. Dealt-with is a statement about the CUSTOMER'S
 *  message: it has been answered, or it needed nothing. Two people
 *  cannot disagree about it the way they can about having read
 *  something.
 *
 *  That difference is why an automation may write here and may not
 *  write to `message_seen`. A rule has no eyes; recording that it
 *  "read" a message would make "Seen by" assert a person saw
 *  something nobody saw, and a colleague would skip a customer's
 *  mail on the strength of it.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── `dealt_by_user_id` is nullable, and that is the automation ───
 *
 * A rule has no user. Null means "a rule did this", which the screen says in
 * those words rather than leaving a blank where a name goes. The audit row
 * carries which rule; this column only has to be honest that it was not a
 * person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_dealt', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('mailbox_id');

            // 191 for the same reason as message_seen: the largest a utf8mb4
            // column can be and still fit a composite unique key under the
            // default index length. A provider decides how long its ids are.
            $table->string('provider_message_id', 191);

            $table->timestamp('dealt_at');

            // Null is a rule rather than a person — see the note above.
            $table->uuid('dealt_by_user_id')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->foreign('dealt_by_user_id')->references('id')->on('users')->nullOnDelete();

            /*
             * One row per message per mailbox, enforced rather than assumed.
             *
             * Marking dealt is idempotent by design — two people clicking it,
             * or a rule racing a person, must not produce two rows and must not
             * fail the second attempt. The unique key is what makes
             * `firstOrCreate` mean that, rather than the application hoping.
             */
            $table->unique(['mailbox_id', 'provider_message_id'], 'message_dealt_unique');

            // The list asks "which of these forty are dealt with" on every page.
            $table->index(['organization_id', 'mailbox_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_dealt');
    }
};
