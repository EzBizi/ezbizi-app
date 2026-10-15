<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\ReviewLinkEvent;
use App\Models\WebSite;
use App\Support\Memberships\CustomerLinks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The review page telling EzBizi what a customer did with their link
 * (Jose, 2026-10-15: "how many click the green, how many click the bad").
 *
 * Only a link that checks out is counted: the public key is in every page's
 * source, so the token is what makes a row. Same answer either way, so the
 * reply says nothing about which tokens are real. First time per link and
 * kind only, so reloading the page does not inflate the count.
 */
class ReviewLinkEventsController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'key'  => ['required', 'string', 'max:100'],
            'c'    => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(ReviewLinkEvent::KINDS)],
        ]);
        $headers = ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'];

        $site = $this->tenant->runWithoutTenant(fn () => WebSite::query()->where('public_key', $input['key'])->first());
        $organization = $site === null ? null
            : $this->tenant->runWithoutTenant(fn () => Organization::query()->find($site->organization_id));

        if ($organization !== null) {
            $this->tenant->run($organization, function () use ($input) {
                $customerId = CustomerLinks::customerId($input['c']);
                if ($customerId === null) {
                    return;
                }

                ReviewLinkEvent::query()->firstOrCreate(
                    ['customer_id' => $customerId, 'job_id' => CustomerLinks::jobId($input['c']), 'kind' => $input['kind']],
                    ['occurred_at' => now()],
                );
            });
        }

        return response()->json(['ok' => true])->withHeaders($headers);
    }
}
