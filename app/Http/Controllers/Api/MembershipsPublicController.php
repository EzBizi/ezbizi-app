<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Models\WebSite;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Memberships\CustomerLinks;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a CUSTOMER reaches of the membership program, with no account
 * (Jose, 2026-10-05): the review faces telling EzBizi who smiled, and the
 * page that signs them up. Spec: docs/superpowers/specs/2026-10-05-memberships-design.md.
 */
class MembershipsPublicController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The website's review faces, by the site's public key. The customer is
     * the one the `c` token names, else the one whose phone or email was
     * typed. The answer is the same whether or not anybody matched: a
     * different one would tell a stranger which phone numbers are customers.
     */
    public function review(Request $request): JsonResponse
    {
        $input = $request->validate([
            'key'     => ['required', 'string', 'max:100'],
            'rating'  => ['required', Rule::in(['happy', 'sad'])],
            'c'       => ['nullable', 'string', 'max:120'],
            'phone'   => ['nullable', 'string', 'max:40'],
            'email'   => ['nullable', 'email', 'max:191'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);
        $headers = ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'];

        $site = $this->tenant->runWithoutTenant(fn () => WebSite::query()->where('public_key', $input['key'])->first());
        if ($site === null) {
            return response()->json(['message' => 'That key is not valid.'], Response::HTTP_NOT_FOUND)->withHeaders($headers);
        }

        $organization = $this->tenant->runWithoutTenant(fn () => Organization::query()->find($site->organization_id));

        $this->tenant->run($organization, function () use ($input, $site) {
            // Verified only when the token itself checks out: a garbage `c`
            // beside a typed phone is still just a typed phone.
            $tokenId = CustomerLinks::customerId($input['c'] ?? null);
            $verified = $tokenId !== null;
            $customer = $verified ? Customer::query()->find($tokenId) : $this->identify($input);

            // Once a day per customer AND kind: a public key is in every
            // page's source, so a typed phone must not let a stranger message
            // someone at will, nor block the customer's own link for a day.
            if ($customer === null || AutomationEvent::query()->where('event_key', 'customer.reviewed')
                ->where('subject_id', $customer->getKey())->where('occurred_at', '>=', now()->subDay())
                ->where('payload->after->verified', $verified)->exists()) {
                return;
            }

            $this->events->emit('customer.reviewed', $customer, ['after' => [
                'rating'              => $input['rating'],
                'comment'             => $input['comment'] ?? null,
                'source'              => $verified ? 'review_link' : 'asked',
                'verified'            => $verified,
                'site'                => $site->host,
                'membership_join_url' => CustomerLinks::joinUrl($customer),
            ]]);
        });

        return response()->json(['ok' => true])->withHeaders($headers);
    }

    /** The sign-up page the offer links to. Signed, so the link is the permission. */
    public function joinPage(string $customer): HttpResponse
    {
        return $this->asCustomer($customer, fn (Customer $c) => $this->page($c));
    }

    public function join(Request $request, string $customer): HttpResponse
    {
        return $this->asCustomer($customer, function (Customer $c) use ($request) {
            $plan = MembershipPlan::query()->where('is_active', true)->find($request->input('plan'));
            if ($plan === null || $this->current($c) !== null) {
                return $this->page($c);
            }

            $starts = CarbonImmutable::today();
            $membership = Membership::create([
                'franchise_id'       => $c->owning_franchise_id,
                'customer_id'        => $c->getKey(),
                'membership_plan_id' => $plan->getKey(),
                'enrollment'         => Membership::query()->where('customer_id', $c->getKey())->exists() ? 'renewal' : 'new',
                'starts_on'          => $starts,
                'ends_on'            => $starts->addYear()->subDay(),
            ]);
            $this->audit->log('membership.created', $membership, metadata: ['by' => 'customer']);
            $this->events->emit('membership.created', $membership, ['after' => MembershipsController::payload($membership)]);

            return $this->page($c);
        });
    }

    private function identify(array $input): ?Customer
    {
        $digits = substr(preg_replace('/\D/', '', (string) ($input['phone'] ?? '')), -10);
        if (strlen($digits) !== 10 && blank($input['email'] ?? null)) {
            return null;
        }

        $matches = Customer::query()
            ->where(function ($q) use ($input, $digits) {
                $q->when(strlen($digits) === 10, fn ($w) => $w->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ['%'.$digits]))
                    ->when(filled($input['email'] ?? null), fn ($w) => $w->orWhere('email', $input['email']));
            })
            ->limit(2)
            ->get();

        // A booker's or household's phone sits on many customers (A-1's staff
        // bookers stay on their houses, Jose's ruling): more than one match
        // names nobody, rather than thanking a random house.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function asCustomer(string $id, \Closure $then): HttpResponse
    {
        $customer = $this->tenant->runWithoutTenant(fn () => Customer::query()->find($id));
        abort_if($customer === null, Response::HTTP_NOT_FOUND);
        $organization = $this->tenant->runWithoutTenant(fn () => Organization::query()->find($customer->organization_id));

        return $this->tenant->run($organization, fn () => $then($customer));
    }

    private function current(Customer $c): ?Membership
    {
        return Membership::query()->where('customer_id', $c->getKey())->whereNull('cancelled_at')
            ->where('ends_on', '>=', CarbonImmutable::today()->toDateString())->latest('ends_on')->first();
    }

    private function page(Customer $c): HttpResponse
    {
        return response()->view('memberships.join', [
            'customer' => $c,
            'company'  => $this->tenant->runWithoutTenant(fn () => Organization::query()->find($c->organization_id))?->name,
            'current'  => $this->current($c)?->load('plan'),
            'plans'    => MembershipPlan::query()->where('is_active', true)->orderBy('price_minor')->get(),
        ]);
    }
}
