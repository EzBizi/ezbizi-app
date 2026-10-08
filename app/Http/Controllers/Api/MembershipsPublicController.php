<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendMembershipVisitToWorkiz;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Models\WebSite;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Jobs\BookJob;
use App\Support\Memberships\CustomerLinks;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a CUSTOMER reaches of the membership program, with no account
 * (Jose, 2026-10-05): the review faces telling EzBizi who smiled, and the
 * page that signs them up. Spec: docs/superpowers/specs/2026-10-05-memberships-design.md.
 */
class MembershipsPublicController extends Controller
{
    /** How far past the year's end the next visit may be booked. */
    public const VISIT_MONTHS_AFTER = 6;

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
                // The job the link was sent for, when it was a job's link.
                'job_id'              => $verified ? CustomerLinks::jobId($input['c'] ?? null) : null,
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

    /**
     * The renewal reminder's link: the customer picks the date of their next
     * visit and it books a job (Jose, 2026-10-05). Signed, so the link is the
     * permission; one visit per membership year.
     */
    public function visitPage(string $membership): HttpResponse
    {
        return $this->asMembership($membership, fn (Membership $m) => $this->visitView($m));
    }

    public function visit(Request $request, string $membership): HttpResponse
    {
        return $this->asMembership($membership, function (Membership $m) use ($request) {
            $date = $request->input('date');
            // From tomorrow to six months past the year's end: the visit is
            // the next year's, and a typo of 2062 is not a booking.
            $last = $m->ends_on->addMonths(self::VISIT_MONTHS_AFTER);
            $valid = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                && CarbonImmutable::parse($date)->gt(CarbonImmutable::today())
                && CarbonImmutable::parse($date)->lte($last);

            if ($m->visit_job_id !== null || $m->cancelled_at !== null || ! $valid) {
                return $this->visitView($m, $valid ? null : 'Please pick a day between tomorrow and '.$last->format('F j, Y').'.');
            }

            $customer = Customer::query()->findOrFail($m->customer_id);
            $franchise = Franchise::query()->findOrFail($m->franchise_id);

            DB::transaction(function () use ($m, $customer, $franchise, $date) {
                // Re-read under a lock: a double-click is two POSTs, and the
                // check above is outside any transaction (the desk, 9a9a0e5a).
                $m = Membership::query()->with('plan')->lockForUpdate()->findOrFail($m->getKey());
                if ($m->visit_job_id !== null) {
                    return;
                }

                $job = app(BookJob::class)($customer, $franchise, 'Yearly maintenance: '.$m->plan->name, $date,
                    ['source' => 'membership', 'membership_id' => $m->getKey()]);
                $m->forceFill(['visit_job_id' => $job->getKey()])->save();

                $this->events->emit('membership.visit_confirmed', $m, ['after' => MembershipsController::payload($m) + [
                    'visit_on'   => $date,
                    'job_number' => $job->number,
                ]]);
            });

            // Into the branch's Workiz too, once the booking has committed.
            if ($m->fresh()->visit_job_id !== null) {
                SendMembershipVisitToWorkiz::dispatch($m->getKey())->afterCommit();
            }

            return $this->visitView($m->fresh());
        });
    }

    private function asMembership(string $id, \Closure $then): HttpResponse
    {
        $m = $this->tenant->runWithoutTenant(fn () => Membership::withoutGlobalScopes()->find($id));
        abort_if($m === null, Response::HTTP_NOT_FOUND);
        $organization = $this->tenant->runWithoutTenant(fn () => Organization::query()->find($m->organization_id));

        return $this->tenant->run($organization, fn () => $then(Membership::query()->with('plan')->findOrFail($id)));
    }

    private function visitView(Membership $m, ?string $error = null): HttpResponse
    {
        $customer = Customer::query()->find($m->customer_id);

        return response()->view('memberships.visit', [
            'membership' => $m,
            'customer'   => $customer,
            'company'    => $this->companyName($customer),
            'job'        => $m->visit_job_id ? \App\Models\Job::query()->find($m->visit_job_id) : null,
            'error'      => $error,
        ]);
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

    /** The customer's company ("A-1 Chimney"), not the organization above it, which they have never heard of. */
    private function companyName(?Customer $c): ?string
    {
        return Franchise::query()->with('company')->find($c?->owning_franchise_id)?->company?->name
            ?? $this->tenant->runWithoutTenant(fn () => Organization::query()->find($c?->organization_id))?->name;
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
            'company'  => $this->companyName($c),
            'current'  => $this->current($c)?->load('plan'),
            'plans'    => MembershipPlan::query()->where('is_active', true)->orderBy('price_minor')->get(),
        ]);
    }
}
