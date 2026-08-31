<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caller identity and trust: the attestation grade, and what a human saw.
 *
 * ── Why the grade is a column on the call ───────────────────────
 *
 * SHAKEN/STIR grades a call A, B or C at the moment it is placed. The grade
 * exists only then — Twilio reports it in the payload and never again, and
 * there is no endpoint that will tell you afterwards what yesterday's calls
 * were graded. So it is captured on the row or it is lost.
 *
 * That is why this screen starts empty and cannot be backfilled: every call
 * EzBizi has ever handled arrived with a grade attached and we dropped it.
 *
 * Inbound arrives as `StirVerstat` on the voice webhook
 * ("TN-Validation-Passed-A"); outbound as `StirStatus` on the status callback
 * ("A"). Both are free and both are in payloads already received. Normalised
 * to a single letter, or 'none' when the call carried no attestation at all —
 * which is itself the answer somebody is looking for.
 *
 * ── Why observations are a table and not a sync ─────────────────
 *
 * No Twilio API reports whether a number currently shows as "Scam Likely" on a
 * given carrier. Not one. The only way to know is for a person to ring the
 * number from a handset on that carrier and write down what appeared. This
 * table is that record, and its emptiness means nobody has looked rather than
 * that a sync failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            // 'A' | 'B' | 'C' | 'none'. Null means the call predates capture,
            // which is a different fact from 'none' and must stay tellable:
            // 'none' is a call that really carried no attestation.
            $table->string('attestation', 8)->nullable()->after('status');
        });

        Schema::create('phone_trust_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('phone_connections')->cascadeOnDelete();

            $table->string('scope_type', 16)->nullable();
            $table->uuid('scope_id')->nullable();

            $table->string('e164', 16);
            $table->string('carrier', 64);

            // What the handset actually showed. Free text on purpose: the
            // labels differ by carrier and change without notice, so a fixed
            // list would be wrong within months.
            $table->string('observed', 191);

            $table->foreignUuid('observed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['organization_id', 'e164']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_trust_observations');

        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn('attestation');
        });
    }
};
