<?php

namespace App\Support\Automations;

use App\Models\Company;
use App\Models\Franchise;
use Illuminate\Database\Eloquent\Model;

/** Capture ownership while the subject and its parents still exist. */
final class EventScope
{
    public static function capture(?Model $subject): array
    {
        [$type, $id] = $subject instanceof CarriesAutomationScope
            ? $subject->automationScope() : [null, null];
        if (! $subject instanceof CarriesAutomationScope && $subject !== null) {
            foreach (['franchise_id', 'owning_franchise_id', 'company_id'] as $attribute) {
                if ($subject->getAttribute($attribute) !== null) {
                    $type = $attribute === 'company_id' ? 'company' : 'franchise';
                    $id = $subject->getAttribute($attribute);
                    break;
                }
            }
        }
        $company = match ($type) {
            'company' => Company::query()->find($id),
            'franchise' => Franchise::query()->find($id)?->company,
            default => null,
        };
        $chain = [];
        while ($company !== null && ! in_array($company->getKey(), $chain, true)) {
            $chain[] = $company->getKey();
            $company = $company->parent;
        }
        return ['type' => $type, 'id' => $id, 'company_chain' => $chain];
    }
}
