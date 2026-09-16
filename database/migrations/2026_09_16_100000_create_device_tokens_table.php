<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to reach a handset that is not running EzBizi (plan §12).
 *
 * The in-app bell and the email channel both reach somebody who is already
 * using the app or already reading their mail. Neither wakes a phone in a
 * pocket, and that is what a push token is for: FCM and APNs will not accept
 * "notify this user", only "deliver to this device", so the mapping from one to
 * the other has to be kept here.
 *
 * ── Why the TOKEN is unique and the user is not ──────────────────
 *
 * A token identifies an INSTALL, not a person. Two people sharing a tablet
 * produce one token and, over time, two owners — so the token is the unique
 * column and `user_id` moves when somebody else signs in on that device.
 * Keying on the user instead would leave the previous person's row pointing at
 * a handset now in somebody else's hands, which is the worst kind of wrong:
 * a notification about a record the new holder may not open, delivered to a
 * device the old holder no longer has.
 *
 * One person legitimately has several rows — a phone, a tablet, a second phone
 * — so nothing here is unique per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');

            // 'android' or 'ios' today. Not an enum: the column outlives the
            // two services that happen to exist, and a third would otherwise
            // need a migration to say a word.
            $table->string('platform', 20);

            /*
             * 255 rather than TEXT because it is looked up and must be unique,
             * and an indexed TEXT needs a prefix length that would then have to
             * be right forever. FCM registration tokens run to roughly 160
             * characters and APNs device tokens to 64 hexadecimal; 255 is
             * comfortable for both and still inside InnoDB's index limit at
             * utf8mb4.
             */
            $table->string('token', 255)->unique();

            // Refreshed every time the client registers, which it does on every
            // launch. A token nobody has presented for months belongs to an app
            // that was deleted, and this column is how a future sweep finds it —
            // FCM and APNs both accept a dead token and drop the message, so
            // silence is not evidence.
            $table->timestamp('last_registered_at');

            $table->timestamp('created_at');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The query every send makes: the devices belonging to the people
            // this notification is for.
            $table->index(['organization_id', 'user_id'], 'device_tokens_recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
