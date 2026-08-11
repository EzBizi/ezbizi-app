<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform control plane (plan §4.2).
 *
 * EzBizi staff are NOT tenant users, and this is not a role in the `roles`
 * table. A tenant user belongs to exactly one organization; staff work across
 * all of them. Putting a cross-tenant identity inside a tenant-scoped table
 * would collapse the boundary the whole system rests on — the BelongsToTenant
 * trait would have to make an exception for it, and every exception to a
 * security boundary is where the next leak comes from.
 *
 * Two separate tables:
 *   platform_staff         — who works for EzBizi
 *   staff_impersonations   — every time one of them reached into a tenant
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            // Globally unique, unlike tenant users (whose email is unique per
            // organization). Staff exist above organizations, so there is no
            // scope for a duplicate to belong to.
            $table->string('email')->unique();
            $table->string('password');

            // lead_developer — everything, including managing staff
            // developer      — platform work; client records only via approved
            //                  impersonation (§4.2)
            $table->enum('tier', ['lead_developer', 'developer'])->default('developer');

            // TOTP is MANDATORY here, not optional. This account can reach every
            // customer's data on the platform, and one account — whichever holds
            // `audit_exempt`, added by a later migration — is deliberately
            // outside the ordinary audit trail. Password-only on that
            // combination is the weakest link in the system.
            //
            // Encrypted at rest: a database leak that exposes TOTP secrets makes
            // the second factor worthless.
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->text('recovery_codes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('tier');
        });

        Schema::create('staff_impersonations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('staff_id');
            $table->uuid('organization_id');

            // Mandatory (§4.2). An impersonation with no stated reason is
            // indistinguishable from an unexplained one when a client asks why
            // their records were opened.
            $table->text('reason');

            // Time-limited by construction, so a forgotten session cannot become
            // standing access.
            $table->timestamp('expires_at');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            // Whether the client approved it, and who. Nullable because the Lead
            // Developer tier may enter without approval — recorded as such
            // rather than silently.
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->foreign('staff_id')->references('id')->on('platform_staff')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->index(['organization_id', 'started_at']);
            $table->index(['staff_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_impersonations');
        Schema::dropIfExists('platform_staff');
    }
};
