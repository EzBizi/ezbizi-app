<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A column shaped like a decision that will not be made.
 *
 * `contract_signatures.provider_evidence` was a JSON blob for a third-party
 * e-signature vendor's identity evidence — their certificate or audit trail,
 * stored verbatim. The owner has confirmed there will be no vendor: EzBizi is
 * building its own signing, which is what the contract tables' own docblock and
 * the HR plan already say ("e-signature is self-built, per the owner").
 *
 * So nothing writes it, nothing reads it, and nothing ever will under that name.
 * That alone would make it dead weight. What makes it worth a migration is the
 * PROMISE attached to it. It was declared in the §9 data inventory as identity
 * data, kept forever, never erased — and the inventory is published to the
 * people it describes. The column therefore committed this organization, in
 * writing, to retaining a person's identity evidence indefinitely for a feature
 * that does not exist and will not be built. A retention promise with nothing
 * behind it is the one kind of inventory entry that is worse than an omission,
 * because it is believed.
 *
 * Evidence that OUR OWN signing collects will be designed against what is
 * actually captured, when it is captured, and declared then. It will not
 * inherit a shape named after somebody else's integration.
 *
 * ── Was there anything in it? ────────────────────────────────────
 *
 * Checked against the production database (`ezbizi` on the app box) on
 * 2026-08-12, before this file was written:
 *
 *     contract_signatures                          0 rows
 *     provider_evidence IS NOT NULL                0 rows
 *
 * The table is empty — the signing ceremony that writes to it has not been run
 * in production yet — so no row loses anything.
 *
 * This paragraph used to go on to say that "the only other schema on that box
 * carrying the column is `ezbizi_testing`". That was wrong, and re-checking it
 * against `information_schema` as an administrator rather than as the
 * production user is what showed it: the production account can only see the
 * schemas it is granted, so it reported the two it could reach and the sentence
 * was written from that answer. Asked properly, twelve other schemas carry the
 * column — `ezbizi_testing` and eleven per-agent `sb_*_testing` sandboxes.
 *
 * The conclusion survives the correction, which is why the sentence is repaired
 * here rather than merely deleted: every one of those is a test database, built
 * from these same migrations and rebuilt by the suite, so none of them holds a
 * person's evidence and this migration will run against each of them the same
 * way. `ezbizi` is the only schema on the box where a row could have mattered,
 * and it has no rows at all.
 *
 * That check was a moment in time, and a moment in time is not a guarantee: the
 * gap between reading it here and running it on the box is a deploy. So the
 * same question is asked again in `up()`, on whatever database this is actually
 * running against, and the migration refuses rather than drops if the answer has
 * changed. A one-line SELECT is a cheap price for never silently discarding
 * somebody's identity evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Already gone — a re-run, or a database built after this landed. Not an
        // error: a migration that cannot be run twice is a migration that turns
        // a partial deploy into a manual repair.
        if (! Schema::hasColumn('contract_signatures', 'provider_evidence')) {
            return;
        }

        /*
         * DB::table, not the model: ContractSignature carries a global tenant
         * scope, and "is anything in this column" is a question about the whole
         * database rather than about one organization. Asked through Eloquent it
         * would answer for whichever tenant happened to be in context — which,
         * in a migration, is none — and a scoped zero would look exactly like a
         * true zero.
         */
        $carrying = DB::table('contract_signatures')
            ->whereNotNull('provider_evidence')
            ->count();

        if ($carrying > 0) {
            throw new RuntimeException(
                "Refusing to drop contract_signatures.provider_evidence: {$carrying} "
                .'signature(s) have something stored in it. It was expected to be empty '
                .'everywhere, because nothing in this application has ever written to it. '
                .'Something else did, and whatever it is, it is identity evidence attached '
                .'to a signature — read those rows and decide where the contents belong '
                .'before running this again.'
            );
        }

        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->dropColumn('provider_evidence');
        });
    }

    public function down(): void
    {
        /*
         * Restores the column, in its old place and its old shape, so a rollback
         * lands on the schema that was there before.
         *
         * It restores the COLUMN and not its contents, and on the day this ran
         * there were none to restore. Said plainly rather than left implied,
         * because "the migration is reversible" and "nothing was lost" are
         * different claims and only the first one is this method's to make.
         */
        Schema::table('contract_signatures', function (Blueprint $table) {
            $table->json('provider_evidence')->nullable()->after('provider_reference');
        });
    }
};
