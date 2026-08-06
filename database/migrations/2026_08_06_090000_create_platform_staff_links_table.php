<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One human, two identities (plan §4.2, revised 2026-08-06).
 *
 * ── Why this is a link and not a column on users ─────────────────
 *
 * The owner asked to use one account for both the CRM and the control plane.
 * The obvious implementation — a "developer" flag on the users table — is the
 * one §4.2 explicitly forbids, and for a reason that has not changed: a tenant
 * user belongs to exactly ONE organization and every row in that table is
 * filtered by organization_id. A flag there granting cross-tenant reach would
 * put the platform's highest privilege inside the boundary it is supposed to
 * cross, and the tenant scope would need an exception for it. Every exception to
 * a security boundary is where the next leak comes from.
 *
 * So the two records stay separate and this table joins them. What changes is
 * AUTHENTICATION, not identity: a linked user proves themselves with the staff
 * credential set — one password, one authenticator, mandatory TOTP — whichever
 * door they use. That is genuinely one account to a human and still two rows to
 * the database, which is what both requirements need.
 *
 * A staff member may link to several users, because one person may hold accounts
 * in several organizations (§4.3). A user may be linked to at most one staff
 * account, which is what the unique key says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('staff_id');
            $table->uuid('user_id');

            // Who tied them together. Linking hands a tenant login the platform's
            // credentials, so it is never a side effect of anything — it is done
            // deliberately, by a Lead Developer, and recorded.
            $table->uuid('linked_by_staff_id')->nullable();
            $table->timestamps();

            $table->foreign('staff_id')->references('id')->on('platform_staff')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // One staff account per user. The reverse is deliberately open: the
            // same person may hold user accounts in several organizations.
            $table->unique('user_id');
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_staff_links');
    }
};
