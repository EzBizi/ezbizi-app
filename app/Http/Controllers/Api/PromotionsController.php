<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendPromotion;
use App\Models\Franchise;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Access\Refusal;
use App\Support\Promotions\Promotions;
use App\Support\Scoping\ViewScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customers > Memberships > Promotions (Jose, 2026-11-06): text the
 * customers of the Done jobs in a date range, in the current view. The tab
 * grant opens it; sending also needs `memberships.edit`.
 */
class PromotionsController extends Controller
{
    public function __construct(
        private readonly Promotions $promotions,
        private readonly AuditLogger $audit,
    ) {}

    /** GET memberships/promotions: what was sent, newest first. */
    public function index(Request $request): JsonResponse
    {
        $this->need($request->user(), 'memberships.promotions.view', 'see promotions');
        $mine = ViewScope::forUser($request->user())->franchiseIds();

        $rows = Promotion::query()->latest()->limit(50)->get()
            ->filter(fn (Promotion $p) => array_diff($p->franchise_ids, $mine) === [])
            ->map(fn (Promotion $p) => [
                'id' => $p->id, 'jobs_from' => $p->jobs_from->toDateString(), 'jobs_to' => $p->jobs_to->toDateString(),
                'body' => $p->body, 'recipients' => $p->recipients, 'sent' => $p->sent, 'failed' => $p->failed,
                'replies' => $p->texts()->whereNotNull('lead_id')->count(),
                'created_at' => $p->created_at?->toIso8601String(), 'finished_at' => $p->finished_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    /** GET memberships/promotions/preview?from=&to=: who would be texted. */
    public function preview(Request $request): JsonResponse
    {
        $this->need($request->user(), 'memberships.promotions.view', 'see promotions');
        [$from, $to] = $this->range($request);
        $all = $this->promotions->recipients(ViewScope::forUser($request->user())->franchiseIds(), $from, $to);
        $list = $all->reject(fn ($r) => $r['opted_out']);

        return response()->json(['data' => [
            'recipients' => $list->count(),
            'opted_out' => $all->count() - $list->count(),
            'sample' => $list->take(25)->map(fn ($r) => ['name' => $r['name'], 'phone' => $r['e164'], 'job_date' => $r['job_date']])->values(),
            'message' => $this->defaultMessage(),
        ]]);
    }

    /** POST memberships/promotions {from, to, body}: queues the texts. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->need($user, 'memberships.promotions.view', 'send promotions');
        $this->need($user, 'memberships.edit', 'send promotions');
        [$from, $to] = $this->range($request);
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'max:600']])['body']);

        $franchises = Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())->get();
        foreach ($franchises as $franchise) {
            if (! $user->canActOnFranchise($franchise)) {
                throw new AuthorizationException(Refusal::noReach($user, Refusal::AT_FRANCHISE, 'send promotions', $franchise->name)->message());
            }
        }
        $count = $this->promotions->recipients($franchises->pluck('id')->all(), $from, $to)->reject(fn ($r) => $r['opted_out'])->count();
        abort_if($count === 0, 422, 'No customer with a phone had a Done job in those dates.');

        $promotion = Promotion::create([
            'jobs_from' => $from->toDateString(), 'jobs_to' => $to->toDateString(), 'body' => $body,
            'franchise_ids' => $franchises->pluck('id')->all(), 'recipients' => $count, 'sent_by' => $user->id,
        ]);
        $this->audit->log('promotion.sent', $promotion, ['recipients' => $count, 'from' => $from->toDateString(), 'to' => $to->toDateString()], actor: $user);
        SendPromotion::dispatch($promotion->id);

        return response()->json(['data' => ['id' => $promotion->id, 'recipients' => $count]], 202);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $in = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return [CarbonImmutable::parse($in['from']), CarbonImmutable::parse($in['to'])];
    }

    private function defaultMessage(): string
    {
        return 'Hi {first_name}, it\'s {company}. It has been about a year since your last service with us. '
            .'Reply to this text to book.';
    }

    private function need(User $user, string $key, string $noun): void
    {
        if (! $user->hasPermission($key)) {
            throw new AuthorizationException(Refusal::permission($noun)->message());
        }
    }
}
