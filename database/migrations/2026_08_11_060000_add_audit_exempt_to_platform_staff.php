<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Lead Developer exemption, keyed on the account instead of the tier.
 *
 * §4.2 says it twice, and §10 says it again: "The exemption is per-account, not
 * per-role — a Developer promoted by mistake shouldn't silently stop being
 * audited. Make it an explicit flag on the single Lead Developer account."
 *
 * It was keyed on `tier`. `PlatformStaff::suppressesBusinessAudit()` returned
 * `$this->tier === 'lead_developer'`, and `tier` is an ordinary editable field:
 * `POST /api/v1/staff/accounts` accepts it at creation and
 * `PATCH /api/v1/staff/accounts/{staff}` accepts it afterwards. So the set of
 * unaudited accounts was not one — it was however many carried that tier, and it
 * grew with a routine promotion that says nothing about auditing anywhere on the
 * screen or in the request. That is the direction that costs you the trail
 * rather than the one that costs you noise.
 *
 * ── The backfill, and why it is conservative ─────────────────────
 *
 * The exemption belongs to the owner's account. Where exactly one Lead Developer
 * exists, that account is unambiguously it and the flag is set, so behaviour is
 * unchanged for the installation the decision was made about.
 *
 * Where there is more than one, this sets NOTHING and every one of them starts
 * being audited. Guessing would reproduce the defect being fixed at the exact
 * moment it is being fixed. The wrong guess in this direction fills a log with
 * development noise, which somebody notices and can undo; the wrong guess in the
 * other direction is an account acting unrecorded that nobody notices at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            // NOT in $fillable on the model, so no mass assignment can reach it.
            // "Who is outside the audit trail" is not a field a request body
            // gets to carry in alongside a name change.
            $table->boolean('audit_exempt')->default(false)->after('tier');
        });

        $leads = DB::table('platform_staff')
            ->where('tier', 'lead_developer')
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($leads->count() === 1) {
            DB::table('platform_staff')
                ->where('id', $leads->first())
                ->update(['audit_exempt' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->dropColumn('audit_exempt');
        });
    }
};
