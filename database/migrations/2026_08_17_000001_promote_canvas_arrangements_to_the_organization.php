<?php

use App\Support\Structure\PromoteCanvasArrangements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * The structure arrangement stops being one person's and becomes the
 * organization's.
 *
 * ── What this is protecting ──────────────────────────────────────
 *
 * `users.preferences['organization.canvas']` held one arrangement per person.
 * The owner's answer to "should this be shared" was "should be shared
 * obviously", so it is now `organization.structure_canvas`, one row per
 * organization in `settings`. Deployed on its own, that is every existing
 * arrangement disappearing at the moment of the deploy: the code stops reading
 * the old key and nothing has written the new one, so everybody's tab opens on
 * the automatic layout with their afternoon of tidying still sitting in a
 * column no code will ever look at again.
 *
 * So the promotion runs here, and the dead key is taken off every user in the
 * same pass. The rule — promote where exactly one person in an organization had
 * an arrangement, promote nothing where two or more did — and the reasoning
 * behind it are in {@see PromoteCanvasArrangements}, which is where they are
 * testable.
 *
 * ── Why the work is in a class and not in this file ──────────────
 *
 * Because a migration is the one kind of code that is hard to run twice on
 * purpose. Putting the election, the cleaning and the strip in an invokable
 * class means PromoteCanvasArrangementsTest can assert what each branch does
 * without migrating a database up and down around every assertion — and the
 * class freezes its own copy of the keys and the cleaning rules for the reason
 * 2026_08_12_120000_grant_export_to_roles_that_already_view gives: a migration
 * has to keep doing what it did the day it ran, and application code moves.
 *
 * ── down() does nothing, and that is the honest answer ───────────
 *
 * Rolling back cannot put this back. The per-user blobs are gone, and which
 * colleague authored a promoted arrangement is information this migration
 * destroyed on purpose — the promoted list carries no author, because the whole
 * point is that it stopped being anybody's.
 *
 * The alternatives were both worse. Copying the shared arrangement back into
 * every user's preferences would invent a choice for people who never made one,
 * and hand somebody else's picture to the whole office under their own name.
 * Deleting the settings row would throw away an arrangement that may well have
 * been saved deliberately since. Doing nothing leaves a tenant on the old code
 * with no arrangement, which is the state a new tenant is in and which one press
 * of "Arrange for me" resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        $done = app(PromoteCanvasArrangements::class)();

        // Written down, because this runs once, silently, against real customer
        // data during a deploy, and there is no other trace of it afterwards.
        // "promoted 0, stripped 0" on a box with real arrangements on it is the
        // signal that something matched nothing, and without a line in the log
        // the first person to notice would be a customer whose chart had reset.
        Log::info('Structure arrangements promoted to their organizations.', $done);
    }

    public function down(): void
    {
        // Deliberately empty. See the class docblock above: the information a
        // reversal would need was destroyed by design, and every way of faking
        // it is worse than leaving tenants on the automatic layout.
    }
};
