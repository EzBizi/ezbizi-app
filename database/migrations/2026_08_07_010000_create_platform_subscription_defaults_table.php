<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * How long a trial lasts, as data (plan §13, §2.1 category 4).
 *
 * It used to be `now()->addMonth()` inside ProvisionOrganization, which was
 * wrong twice over.
 *
 * First, the arithmetic. §13 rejects calendar months in as many words:
 * "Recommendation: 30 calendar days from activation, evaluated in UTC ...
 * Calendar months make a February trial shorter than a March one for no reason
 * a customer will accept." addMonth() is exactly that — a February signup got
 * 28 days and a July one got 31, from the same sentence in the same contract.
 *
 * Second, the ownership. The owner's words were "sales can extend it", and a
 * number that only changes by deploying cannot be extended by sales. §2.1 keeps
 * six categories apart; this is category 4, an ENTITLEMENT, owned by billing.
 * That rules out both ends of the obvious answer: not `config/ezbizi.php`,
 * which is deployment config and needs a release; and not the tenant settings
 * registry, because a tenant able to set their own trial length is the billing
 * hole §13 names outright.
 *
 * So it lives here, on the control plane — one row, no organization_id, the
 * same shape as platform_module_flags. Changing it changes the length of every
 * trial started afterwards, with no deployment and nothing a tenant can reach.
 *
 * A single organization overrides it through `organizations.trial_ends_at`.
 * §13 requires the exact expiry TIMESTAMP to be stored rather than recomputed,
 * so moving that date IS the override, and it survives any later change to the
 * default.
 *
 * What does not exist yet, stated because the sentence above reads as though it
 * does: nothing in the application writes either value. `trial_ends_at` is
 * written once at provisioning and read once for display; `trial_days` has no
 * editor. So "sales extends a customer's trial" is currently an UPDATE typed by
 * hand against the database. The columns are the right shape and the decision is
 * recorded (TODO #17) — the screen is not built, and neither is the signup form
 * that would be the first thing to write them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_defaults', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Days, not months — see the docblock above.
            $table->unsignedSmallInteger('trial_days')->default(30);

            // Staff attribution, matching platform_module_flags. Platform-plane
            // identity, never a tenant user (§4.2).
            $table->string('changed_by')->nullable();

            $table->timestamps();
        });

        // Seeded here so the value exists before the first signup, and so
        // "what is the trial length?" is answerable by reading one row rather
        // than by knowing what the code falls back to.
        DB::table('platform_subscription_defaults')->insert([
            'id'         => (string) Str::uuid7(),
            'trial_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_defaults');
    }
};
