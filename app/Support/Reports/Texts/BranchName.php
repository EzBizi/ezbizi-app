<?php

namespace App\Support\Reports\Texts;

use App\Models\Franchise;
use Illuminate\Support\Facades\DB;

/**
 * A branch's name as people outside it read it: its company's name when the
 * company has one branch, "Company · Branch" when it has several. Jose,
 * 2026-10-09, on the WhatsApp groups: "they all say main or texas, u cant tell
 * which company they belong to" (every new company's branch is "Main").
 */
final class BranchName
{
    public static function of(Franchise $branch): string
    {
        $company = DB::table('companies')->where('id', $branch->company_id)->value('name');

        if ($company === null) {
            return $branch->name;
        }

        // Soft-deleted branches do not count: a company down to one reads as one.
        $branches = DB::table('franchises')
            ->where('company_id', $branch->company_id)
            ->whereNull('deleted_at')
            ->count();

        return $branches > 1 ? "{$company} · {$branch->name}" : (string) $company;
    }
}
