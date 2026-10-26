<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\Pending\PendingCheck;
use App\Support\Settings\Pending\PendingRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /settings/pending — what each place in the CURRENT VIEW is missing.
 *
 * Only gaps this person could fill are listed: a check whose permission they
 * lack (including one whose module is switched off) is not theirs to act on.
 * Grouped organization › company › place, in that order.
 */
class PendingSettingsController
{
    public function __construct(
        private readonly PendingRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $scope = ViewScope::forUser($user);

        $places = [];

        if ($scope->isOrganization() && $user->canActOnOrganization($user->organization)) {
            $places[] = ['organization', $user->organization];
        }

        // Companies from the organization or a company view only: standing in
        // one place, its company's gaps are not that place's to fill. At the
        // organization view, the companies this person reaches.
        $companyIds = match (true) {
            $scope->type === ViewScope::FRANCHISE => [],
            $scope->isOrganization() => $user->canActOnOrganization($user->organization)
                ? null
                : $user->reachableFranchises()->pluck('company_id')->unique()->all(),
            default => $scope->companyIdsInView(),
        };

        $companies = Company::query()->orderBy('name')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('id', $companyIds))
            ->get();

        foreach ($companies as $company) {
            $places[] = ['company', $company];
        }

        foreach (Franchise::query()->whereIn('id', $scope->franchiseIds())->orderBy('name')->get() as $franchise) {
            $places[] = ['franchise', $franchise];
        }

        $checks = array_filter(
            $this->registry->all(),
            // hasPermission() also refuses a permission whose module this
            // organization does not use, so a switched-off module asks nothing.
            fn (PendingCheck $check) => collect($check->permissions)->every(fn (string $p) => $user->hasPermission($p)),
        );

        $groups = [];

        foreach ($places as [$type, $place]) {
            $items = [];

            foreach ($checks as $check) {
                if (in_array($type, $check->scopes, true) && ($check->missing)($place)) {
                    $items[] = [
                        'key'    => $check->key,
                        'label'  => $check->label,
                        'module' => $check->module,
                        'fill'   => ($check->fill)($place),
                    ];
                }
            }

            if ($items !== []) {
                $groups[] = [
                    'place' => [
                        'type'       => $type,
                        'id'         => $place->getKey(),
                        'name'       => $place->name,
                        'company_id' => $place instanceof Franchise ? $place->company_id : null,
                    ],
                    'items' => $items,
                ];
            }
        }

        return response()->json([
            'data' => $groups,
            'meta' => ['count' => array_sum(array_map(fn (array $g) => count($g['items']), $groups))],
        ]);
    }
}
