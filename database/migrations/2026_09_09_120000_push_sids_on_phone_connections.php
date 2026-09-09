<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SIDs of the push credentials that let Twilio wake a phone.
 *
 * A call reaches a browser down a socket the browser is holding open. It
 * cannot reach a locked iPhone that way — Twilio has to send a VoIP push, and
 * it will only do that when the ACCESS TOKEN names a push credential. Ours
 * named none, so TestFlight builds registered, placed calls, showed themselves
 * as connected, and never rang. There was no error to find: outgoing needs
 * only the TwiML application, which was set.
 *
 * ── Why here and not in config ───────────────────────────────────
 *
 * A push credential belongs to the Twilio account that mints the token, and
 * EzBizi mints from each company's own subaccount. One shared constant would
 * name a credential on the parent account, which the subaccount's token cannot
 * use. So: one credential per connection, beside the API key it is used with.
 *
 * Not a secret — a CR… SID, like `twiml_app_sid` two columns up. The APNs key
 * and the Google service account behind them are uploaded to Twilio once and
 * never come back out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->string('push_credential_ios_sid')->nullable()->after('twiml_app_sid');
            $table->string('push_credential_android_sid')->nullable()->after('push_credential_ios_sid');
        });
    }

    public function down(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->dropColumn(['push_credential_ios_sid', 'push_credential_android_sid']);
        });
    }
};
