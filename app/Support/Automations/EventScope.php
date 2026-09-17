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
        return ['type' => $type, 'id' => $id, 'company_chain' => self::chainFor($type, $id)];
    }

    /**
     * Every company above a place, nearest first.
     *
     * Split out because the RULE LIST asks the same question the engine asks,
     * from the other end: the engine holds an event and wants the rules that
     * cover it, the list holds a view and wants the rules that reach it. Both
     * answers turn on this walk, and two copies of it would drift into a list
     * showing rules that do not run and hiding rules that do.
     *
     * @return list<string>
     */
    public static function chainFor(?string $type, ?string $id): array
    {
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

        return $chain;
    }
}
