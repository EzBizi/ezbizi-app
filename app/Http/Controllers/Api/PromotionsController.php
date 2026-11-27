<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendPromotion;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Phone\TextRefused;
use App\Support\Promotions\Promotions;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly FileStore $files,
        private readonly TenantContext $tenant,
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
                'body' => $p->body, 'picture' => $p->media_path !== null, 'recipients' => $p->recipients, 'sent' => $p->sent, 'failed' => $p->failed,
                'replies' => $p->texts()->whereNotNull('lead_id')->count(),
                'created_at' => $p->created_at?->toIso8601String(), 'finished_at' => $p->finished_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET memberships/promotions/preview?from=&to=: everyone the dates reach,
     * with what the screen filters on, and the line it would send from.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->need($request->user(), 'memberships.promotions.view', 'see promotions');
        [$from, $to] = $this->range($request);
        $all = $this->promotions->recipients(ViewScope::forUser($request->user())->franchiseIds(), $from, $to);
        $list = $all->reject(fn ($r) => $r['opted_out'])->values();

        $senders = $list->pluck('franchise_id')->unique()->map(function (string $id) {
            $line = $this->promotions->sender($id);

            return $line === null ? null : ['e164' => $line->e164, 'label' => $line->label, 'mms' => $this->promotions->takesPictures($line)];
        })->filter()->unique('e164')->values();

        return response()->json(['data' => [
            'recipients' => $list->count(),
            'opted_out' => $all->count() - $list->count(),
            'rows' => $list->map(fn ($r) => ['phone' => $r['e164']] + collect($r)->except(['e164', 'opted_out', 'customer_id', 'job_id'])->all()),
            'services' => Service::query()->whereIn('id', $list->pluck('jobs.*.services')->flatten()->unique())->pluck('name', 'id'),
            'senders' => $senders,
            'message' => $this->defaultMessage(),
        ]]);
    }

    /** POST memberships/promotions {from, to, body, phones[], media_path?}: queues the texts. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $franchises = $this->sendable($user);
        [$from, $to] = $this->range($request);
        $in = $request->validate([
            'body' => ['required', 'string', 'max:600'],
            'phones' => ['required', 'array', 'min:1'],
            'phones.*' => ['string'],
            'media_path' => ['nullable', 'string'],
            // The Leads tab its answers land in (Shoval, 2026-11-06).
            'name' => ['nullable', 'string', 'max:60'],
        ]);
        $media = $this->ownMedia($in['media_path'] ?? null);

        $reach = $this->promotions->recipients($franchises->pluck('id')->all(), $from, $to)->reject(fn ($r) => $r['opted_out'])->pluck('e164')->flip();
        $phones = array_values(array_unique(array_filter($in['phones'], fn ($p) => isset($reach[$p]))));
        abort_if($phones === [], 422, 'None of those customers had a Done job in those dates.');

        $promotion = Promotion::create([
            'name' => filled($in['name'] ?? null) ? trim($in['name']) : null,
            'jobs_from' => $from->toDateString(), 'jobs_to' => $to->toDateString(), 'body' => trim($in['body']), 'media_path' => $media,
            'franchise_ids' => $franchises->pluck('id')->all(), 'phones' => $phones, 'recipients' => count($phones), 'sent_by' => $user->id,
        ]);
        $this->audit->log('promotion.sent', $promotion, ['recipients' => count($phones), 'from' => $from->toDateString(), 'to' => $to->toDateString()], actor: $user);
        SendPromotion::dispatch($promotion->id);

        return response()->json(['data' => ['id' => $promotion->id, 'recipients' => count($phones)]], 202);
    }

    /**
     * POST memberships/promotions/test {to, body, franchise_id, first_name?, company?, media_path?}:
     * one text to a typed number, marked TEST and not part of any promotion.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $franchises = $this->sendable($user);
        $in = $request->validate([
            'to' => ['required', 'string'],
            'body' => ['required', 'string', 'max:600'],
            'franchise_id' => ['required', 'string'],
            'first_name' => ['nullable', 'string', 'max:60'],
            'company' => ['nullable', 'string', 'max:120'],
            'media_path' => ['nullable', 'string'],
        ]);
        $to = (string) Lead::e164($in['to']);
        if (! preg_match('/^\+\d{10,15}$/', $to)) {
            throw ValidationException::withMessages(['to' => 'That is not a phone number.']);
        }
        abort_unless($franchises->contains('id', $in['franchise_id']), 422, 'That branch is not in your view.');

        try {
            $sent = $this->promotions->sendOne($in['franchise_id'], $to, 'TEST: '.Promotions::fill(trim($in['body']), $in),
                Promotions::mediaUrls($this->ownMedia($in['media_path'] ?? null)));
        } catch (TextRefused $e) {
            abort(422, $e->getMessage());
        }
        $this->audit->log('promotion.test_sent', $sent, ['to' => $to], actor: $user);

        return response()->json(['data' => ['to' => $to, 'body' => $sent->body]]);
    }

    /** POST memberships/promotions/media (image): the picture a promotion carries. */
    public function upload(Request $request): JsonResponse
    {
        $this->sendable($request->user());
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120']]);

        return response()->json(['data' => [
            'media_path' => $this->files->put($request->file('image'), $this->files->directoryFor('promotions', $this->tenant->idOrFail())),
        ]], 201);
    }

    /** A picture path from the client, only if it is under this company's promotions. */
    private function ownMedia(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        abort_unless(str_starts_with($path, $this->files->directoryFor('promotions', $this->tenant->idOrFail()).'/') && ! str_contains($path, '..'), 422, 'That picture is not this company\'s.');

        return $path;
    }

    /** GET promotions/media/{path}, signed: Twilio fetches the picture here. */
    public function show(string $path, FileStore $files): StreamedResponse
    {
        return $files->serve($path, disposition: 'inline');
    }

    /** Sending needs the tab and `memberships.edit`, and reach over every branch in view. */
    private function sendable(User $user): Collection
    {
        $this->need($user, 'memberships.promotions.view', 'send promotions');
        $this->need($user, 'memberships.edit', 'send promotions');
        $franchises = Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())->get();
        foreach ($franchises as $franchise) {
            if (! $user->canActOnFranchise($franchise)) {
                throw new AuthorizationException(Refusal::noReach($user, Refusal::AT_FRANCHISE, 'send promotions', $franchise->name)->message());
            }
        }

        return $franchises;
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
