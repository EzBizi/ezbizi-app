<?php

use App\Models\Permission;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bad Ratings, the review link's sad-face form, became a Leads tab of their
 * own (Jose, 2026-10-19). They were website forms, so every role that could see
 * Forms keeps seeing them: `leads.bad_rating.view` to each role holding
 * `leads.website_form.view`. Only this key, never a registry sweep (see
 * 2026_10_15_135523).
 */
return new class extends Migration
{
    public function up(): void
    {
        $formsId = Permission::query()->where('key', 'leads.website_form.view')->value('id');

        if ($formsId === null) {
            return;
        }

        $permission = app(PermissionCatalogue::class)->materialise('leads.bad_rating.view');

        DB::table('permission_role')->insertOrIgnore(
            DB::table('permission_role')->where('permission_id', $formsId)->pluck('role_id')
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
        );
    }

    public function down(): void {}
};
