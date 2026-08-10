<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the Administrator role a real discriminator (plan §4).
 *
 * ── The bug this closes ──────────────────────────────────────────
 *
 * `Role::isAdministrator()` matched on the ENGLISH DISPLAY NAME:
 *
 *     return $this->is_system && $this->name === self::ADMINISTRATOR;
 *
 * while `name` is fillable and the roles screen is about to be able to write it.
 * Rename the role and every implicit permission evaporates — `hasPermission()`
 * stops short-circuiting, the role holds no `permission_role` rows because it
 * never needed any, and the person who made the change is the one who can no
 * longer reach the roles screen to change it back. There is no way out of that
 * from inside the product.
 *
 * Identity now lives in a column nothing user-facing can touch:
 * `is_administrator` is deliberately absent from Role::$fillable, so no request
 * payload, mass-assignment or role-editor endpoint can set or clear it. Only
 * ProvisionOrganization writes it, once, with the organization.
 *
 * ── Why the backfill does NOT match on the name ──────────────────
 *
 * Matching `name = 'Administrator'` here would carry the same defect into the
 * data: an organization that had already renamed its top role would be skipped
 * and stay broken. Nothing except ProvisionOrganization has ever created a role
 * with `is_system = true`, so every such row IS the provisioned Administrator
 * whatever it is now called — and marking it recovers the renamed case instead
 * of freezing it.
 *
 * Free to run today: zero organizations have a second role, so there is nothing
 * this can mislabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_administrator')->default(false)->after('is_system');
        });

        DB::table('roles')->where('is_system', true)->update(['is_administrator' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_administrator');
        });
    }
};
