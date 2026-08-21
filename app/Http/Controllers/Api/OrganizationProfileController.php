<?php

namespace App\Http\Controllers\Api;

use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organization's own profile — the facts a contract names about the business.
 *
 * Not a setting in the cascade. Address is one home for one fact, the same
 * argument CommonCore makes about a person: a template field called
 * `employer_address` would be a second copy that nothing keeps in step with
 * the business. This is that home.
 */
class OrganizationProfileController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function update(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('organizations.edit'),
            403,
            Refusal::permission('change the organization’s address')->message(),
        );

        $input = $request->validate([
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $organization = $this->tenant->get();
        $organization->fill($input);
        $changed = $organization->getDirty();

        if ($changed !== []) {
            $organization->save();
            $this->audit->log('organization.profile_updated', $organization, changes: $changed);
        }

        return response()->json(['data' => [
            'id'      => $organization->id,
            'name'    => $organization->name,
            'address' => $organization->address,
        ]]);
    }
}
