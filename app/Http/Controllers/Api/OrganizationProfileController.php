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
 *
 * Structured the same way a company and a customer are: line, suite, city,
 * state, postal. `address` is the formatted line contracts merge, kept in
 * step with those fields so {{organization.address}} does not grow a second
 * source of truth.
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

        foreach (['address', 'address_line1', 'address_line2', 'city', 'state', 'postal_code'] as $field) {
            if ($request->exists($field) && $request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $input = $request->validate([
            'address'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'state'         => ['sometimes', 'nullable', 'string', 'size:2'],
            'postal_code'   => ['sometimes', 'nullable', 'string', 'max:12'],
        ]);

        $organization = $this->tenant->get();

        if ($this->hasStructuredAddress($input)) {
            foreach (['address_line1', 'address_line2', 'city', 'state', 'postal_code'] as $field) {
                if (array_key_exists($field, $input)) {
                    $organization->{$field} = $input[$field];
                }
            }
            if (is_string($organization->state)) {
                $organization->state = strtoupper($organization->state);
            }
            $organization->address = $organization->formattedAddress();
        } elseif (array_key_exists('address', $input)) {
            $organization->address = $input['address'];
        }

        $changed = $organization->getDirty();

        if ($changed !== []) {
            $organization->save();
            $this->audit->log('organization.profile_updated', $organization, changes: $changed);
        }

        return response()->json(['data' => $this->present($organization)]);
    }

    /** @param  array<string, mixed>  $input */
    private function hasStructuredAddress(array $input): bool
    {
        foreach (['address_line1', 'address_line2', 'city', 'state', 'postal_code'] as $field) {
            if (array_key_exists($field, $input)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function present(\App\Models\Organization $organization): array
    {
        return [
            'id'            => $organization->id,
            'name'          => $organization->name,
            'address'       => $organization->address,
            'address_line1' => $organization->address_line1,
            'address_line2' => $organization->address_line2,
            'city'          => $organization->city,
            'state'         => $organization->state,
            'postal_code'   => $organization->postal_code,
        ];
    }
}
