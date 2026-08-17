<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The oldest client build each platform is still allowed to talk to.
 *
 * ── Why this table exists ────────────────────────────────────────
 *
 * EzBizi is shipping installed apps — Windows first, then Android, iOS and
 * macOS — beside the web client. An installed app does not refresh itself the
 * way a browser tab does: it sits on a customer's machine at whatever version
 * they installed, for as long as they never think about it. When the API moves
 * on, that stale copy does not fail with "you are out of date", it fails with a
 * validation error on a field it does not know about, or a blank screen, or a
 * crash — and the customer reports a bug in software that was fixed months ago.
 *
 * This table lets the server say the true thing instead: this app is too old to
 * talk to us, here is where the newer one lives.
 *
 * ── A PLATFORM table, deliberately ───────────────────────────────
 *
 * Modelled on platform_module_flags: no organization_id, no BelongsToTenant.
 * EzBizi staff own these rows and organizations can neither see nor change
 * them. That is not an oversight to be corrected later — a customer who could
 * set their own minimum could set it to zero and keep a client alive that the
 * server can no longer serve correctly, which is precisely the failure this
 * exists to prevent. It is also not a per-tenant question: which builds the API
 * can still serve is a fact about the API, and the API is one installation.
 *
 * ── One row per platform, never one global number ────────────────
 *
 * The unique key is the platform and there is no "all platforms" row, because a
 * single global minimum is actively dangerous. Windows can ship a fix in
 * minutes over MSIX; iOS sits in App Store review for days. The day you raise a
 * shared number because Windows is ready, every iOS customer is locked out of a
 * build they physically cannot obtain yet. Separate rows make "raise Windows,
 * leave iOS where it is" the natural thing to do rather than the thing you have
 * to remember.
 *
 * ── Everything here is nullable, and that is the safety property ─
 *
 * A platform with no row, or a row with a null minimum_version, blocks nobody.
 * The gate fails OPEN (see EnforceMinimumClientVersion for the full reasoning),
 * so the empty state of this table is the harmless state, and clearing a
 * minimum is the one-request undo when a raise turns out to have been wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_minimum_client_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * web, windows, macos, linux, android, ios.
             *
             * A string rather than an enum, even though the set is closed and
             * known. MySQL enums are edited by ALTER TABLE, so the day EzBizi
             * ships a client for something not in this list — a tablet target,
             * a kiosk build — adding it would mean a migration on a production
             * table before a single row could be written. The authoritative list
             * lives in code, on PlatformMinimumClientVersion::PLATFORMS, where
             * the staff endpoint validates against it and the middleware reads
             * it. A row whose platform is not in that list is inert: nothing
             * ever looks it up.
             */
            $table->string('platform')->unique();

            /*
             * The oldest build allowed, in EzBizi's own version format —
             * "0.0.0030", the contents of client/VERSION.
             *
             * NULLABLE, and null is not the same as "0". Null means nobody has
             * ever set a minimum for this platform and therefore no client is
             * refused; it is also what a staff member writes to lift a minimum
             * that turned out to be too aggressive. Storing "0.0.0000" for the
             * same idea would work by arithmetic accident and would read, to
             * anyone looking at the table later, as a decision somebody made.
             *
             * A string rather than an integer: versions have segments, the
             * segments are compared left to right, and "0.0.0030" is not a
             * number. Comparison lives on the model.
             */
            $table->string('minimum_version')->nullable();

            /*
             * Where the newer build actually is.
             *
             * Nullable because it is genuinely absent for `web` — a browser
             * client has nothing to download, it reloads — and because a
             * platform may be gated before its store listing exists. When it is
             * null the refusal still tells the customer what to do; it just
             * cannot hand them a link, and it says so rather than trailing off.
             *
             * Per platform rather than one shared link, because the answer
             * genuinely differs: an App Store URL, a Play Store URL, an MSIX
             * feed and a .dmg are four different addresses and no single one of
             * them is right for the others.
             */
            $table->string('download_url', 2048)->nullable();

            /*
             * One short sentence from staff, shown to the customer under the
             * refusal — "this update fixes invoice totals", or "we will stop
             * accepting the old app on Friday".
             *
             * Optional, and short on purpose. It is a note, not a release note:
             * the refusal screen has to stay readable at 375px.
             */
            $table->text('note')->nullable();

            // Who last changed it. Platform-plane identity — a PlatformStaff
            // label, never a tenant user (§4.2). Overwritten by the next change,
            // which is why the audit log carries the real history.
            $table->string('changed_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_minimum_client_versions');
    }
};
