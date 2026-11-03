<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessLocation;
use App\Models\PhoneNumber;
use App\Models\Review;
use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use App\Services\BusinessProfile\BusinessProfileApi;
use App\Support\CustomerExperience\Places;
use App\Support\Memberships\CustomerLinks;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What Rodrigo's system did for a listing beyond its info (A1 Leads takeover,
 * design 154): media and services with copy between listings, the phone
 * check, review sources, the WhatsApp link. Writes to Google stay behind
 * services.google_business_profile.write.
 */
class BusinessProfileListingController extends Controller
{
    public function __construct(private readonly BusinessProfileApi $api) {}

    /** GET /business-profile/locations/{location}/media */
    public function media(BusinessLocation $location): JsonResponse
    {
        $this->authorize('view', $location);

        return $this->live(fn () => $this->api->media($location));
    }

    /** POST /business-profile/locations/{location}/media/copy {media: [name], targets: [id]} */
    public function copyMedia(Request $request, BusinessLocation $location): JsonResponse
    {
        $this->authorize('update', $location);
        $input = $request->validate(['media' => ['required', 'array', 'min:1'], 'media.*' => ['string'],
            'targets' => ['required', 'array', 'min:1'], 'targets.*' => ['uuid']]);
        if ($refused = $this->refused()) {
            return $refused;
        }
        $items = collect($this->api->media($location))->whereIn('name', $input['media']);

        return $this->eachTarget($input['targets'], function (BusinessLocation $target) use ($items) {
            foreach ($items as $m) {
                $this->api->addMedia($target, (string) $m['url'], $m['category'], $m['format'] === 'VIDEO' ? 'VIDEO' : 'PHOTO');
            }
        });
    }

    /** GET /business-profile/locations/{location}/services */
    public function services(BusinessLocation $location): JsonResponse
    {
        $this->authorize('view', $location);

        return $this->live(fn () => $this->api->categoriesAndServices($location));
    }

    /** PUT /business-profile/locations/{location}/services {services: [Google serviceItems]} */
    public function replaceServices(Request $request, BusinessLocation $location): JsonResponse
    {
        $this->authorize('update', $location);
        $input = $request->validate(['services' => ['present', 'array']]);

        return $this->live(fn () => $this->api->replaceServices($location, $input['services']));
    }

    /**
     * POST /business-profile/locations/{location}/services/copy {targets: [id], apply: bool}
     * Without apply, the per-listing diff only (design 154 #4).
     */
    public function copyServices(Request $request, BusinessLocation $location): JsonResponse
    {
        $this->authorize('update', $location);
        $input = $request->validate(['targets' => ['required', 'array', 'min:1'], 'targets.*' => ['uuid'], 'apply' => ['boolean'],
            'mode' => ['nullable', 'in:replace,add_missing']]);
        $addOnly = ($input['mode'] ?? 'replace') === 'add_missing';
        if (($input['apply'] ?? false) && $refused = $this->refused()) {
            return $refused;
        }
        $source = $this->api->categoriesAndServices($location)['services'];
        $label = fn (array $s) => $s['freeFormServiceItem']['label']['displayName'] ?? $s['structuredServiceItem']['serviceTypeId'] ?? '';
        $names = array_values(array_filter(array_map($label, $source)));

        if (! ($input['apply'] ?? false)) {
            return response()->json(['data' => $this->targets($input['targets'])->map(function (BusinessLocation $t) use ($names, $label) {
                $have = array_values(array_filter(array_map($label, $this->api->categoriesAndServices($t)['services'])));

                return ['id' => $t->id, 'name' => $t->label(), 'has' => $have, 'adds' => array_values(array_diff($names, $have)),
                    'removes' => $addOnly ? [] : array_values(array_diff($have, $names))];
            })->values()->all()]);
        }

        return $this->eachTarget($input['targets'], function (BusinessLocation $t) use ($source, $label, $addOnly) {
            $services = $source;
            if ($addOnly) {
                $current = $this->api->categoriesAndServices($t)['services'];
                $have = array_map($label, $current);
                $services = [...$current, ...array_filter($source, fn ($s) => ! in_array($label($s), $have, true))];
            }
            $this->api->replaceServices($t, array_values($services));
        });
    }

    /** GET /business-profile/phone-check: the listing's number vs EzBizi's lines vs the website's (design 154 #10). */
    public function phoneCheck(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BusinessLocation::class);
        $digits = fn (?string $n) => $n === null ? null : (substr(preg_replace('/\D/', '', $n), -10) ?: null);
        $query = BusinessLocation::query()->whereNull('not_listed_since')->with('franchise')->orderBy('title');
        Places::scope($query, $request->user());

        return response()->json(['checked_at' => now()->toIso8601String(), 'data' => $query->get()->map(function (BusinessLocation $l) use ($digits) {
            // Lines may sit on the branch or on the company (A1 keeps all 14 on the company).
            $lines = PhoneNumber::query()->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('scope_type', PhoneNumber::SCOPE_FRANCHISE)->where('scope_id', $l->franchise_id))
                ->orWhere(fn ($q) => $q->where('scope_type', PhoneNumber::SCOPE_COMPANY)->where('scope_id', $l->company_id)))
                ->pluck('e164')->all();
            $website = $l->franchise ? CustomerLinks::branchPhone($l->franchise) : null;
            $listing = $digits($l->phone);
            $ours = array_map($digits, $lines);

            // The website often shows one central number for every branch, so it only decides when EzBizi has no lines.
            return ['id' => $l->id, 'name' => $l->label(), 'branch' => $l->franchise?->name,
                'listing_phone' => $l->phone, 'ezbizi_lines' => $lines, 'website_phone' => $website,
                'status' => match (true) {
                    $listing === null => 'missing',
                    $ours !== [] => in_array($listing, $ours, true) ? 'match' : 'mismatch',
                    $website === null => 'unknown',
                    default => $digits($website) === $listing ? 'match' : 'mismatch',
                }];
        })->values()->all()]);
    }

    /** GET /reviews/sources: reviews by where they were written (design 154 #13). Only Google is connected so far. */
    public function sources(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Review::class);
        $query = Review::query()->whereNotNull('rating');
        Places::scope($query, $request->user());
        $by = $query->toBase()->select('source', DB::raw('avg(rating) as average, count(*) as n'))->groupBy('source')->get()->keyBy('source');
        $row = fn (string $source, bool $connected, $last) => ['source' => $source, 'connected' => $connected,
            'count' => (int) ($by[$source]->n ?? 0), 'average' => isset($by[$source]) ? round((float) $by[$source]->average, 2) : null,
            'last_sync' => $last];
        $ekomi = SocialConnection::query()->where('provider', SocialProvider::Ekomi->value);

        return response()->json(['data' => [
            $row(Review::SOURCE_GOOGLE, true, BusinessLocation::query()->max('synced_at')),
            $row(Review::SOURCE_EKOMI, (clone $ekomi)->exists(), $ekomi->max('last_reached_at')),
            // ponytail: Thumbtack exposes no reviews API to partners; stays unconnected until it does.
            ['source' => 'thumbtack', 'connected' => false, 'count' => 0, 'average' => null, 'last_sync' => null],
        ], 'reply_rule_id' => $this->replyRuleId()]);
    }

    /** The live rule that posts review replies: the send window lives on it (design 154 #7). */
    private function replyRuleId(): ?string
    {
        return \App\Models\Automation::query()->where('is_active', true)->whereNull('disabled_at')
            ->whereNotNull('active_version_id')->with('activeVersion')->orderBy('name')->get()
            ->first(fn ($rule) => str_contains((string) json_encode($rule->activeVersion?->actions ?? []), '"publish_reply"'))?->id;
    }

    /**
     * GET /business-profile/whatsapp-links: each listing's WhatsApp button on
     * Google against the organization's link, the preview of the bulk editor.
     */
    public function whatsappLinks(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BusinessLocation::class);
        $proposed = self::whatsappLink();
        $query = BusinessLocation::query()->whereNull('not_listed_since')->with(['franchise', 'connection'])->orderBy('title');
        Places::scope($query, $request->user());

        // ponytail: one Google read per listing, in sequence; pool them if a tenant passes a few hundred listings.
        // Every number the organization has, so the sheet can pick one per company (Jose, 2026-11-02).
        $numbers = WhatsAppNumber::query()->orderBy('created_at')->pluck('display_number')
            ->map(fn ($n) => ['number' => $n, 'link' => 'https://wa.me/'.preg_replace('/\D/', '', (string) $n)])->values()->all();

        return response()->json(['proposed' => $proposed, 'numbers' => $numbers, 'data' => $query->get()->map(function (BusinessLocation $l) use ($proposed) {
            try {
                $current = $this->api->whatsappLink($l);
                $state = match (true) {
                    $current === null => 'missing',
                    $current === $proposed => 'same',
                    default => 'different',
                };
            } catch (Throwable) {
                [$current, $state] = [null, 'unreachable'];
            }

            return ['id' => $l->id, 'name' => $l->label(), 'branch' => $l->franchise?->name, 'current' => $current, 'state' => $state];
        })->values()->all()]);
    }

    /**
     * POST /business-profile/whatsapp-links/apply {targets: [id], link?: "https://wa.me/<digits>"}:
     * puts the chosen link (one listing or many) on each; without one, the organization's.
     */
    public function applyWhatsappLinks(Request $request): JsonResponse
    {
        $input = $request->validate(['targets' => ['required', 'array', 'min:1'], 'targets.*' => ['uuid'],
            'link' => ['nullable', 'string', 'regex:#^https://wa\.me/\d{8,15}$#']]);
        if ($refused = $this->refused()) {
            return $refused;
        }
        if (($link = $input['link'] ?? self::whatsappLink()) === null) {
            return response()->json(['message' => 'Connect a WhatsApp number first.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->eachTarget($input['targets'], fn (BusinessLocation $t) => $this->api->setWhatsappLink($t, $link));
    }

    /** The organization's WhatsApp chat link, for every listing's Info tab (design 154 #16). */
    public static function whatsappLink(): ?string
    {
        return once(function () {
            $number = preg_replace('/\D/', '', (string) WhatsAppNumber::query()->orderBy('created_at')->value('display_number'));

            return $number === '' ? null : 'https://wa.me/'.$number;
        });
    }

    private function targets(array $ids)
    {
        $query = BusinessLocation::query()->whereKey($ids);

        return $query->get()->each(fn (BusinessLocation $t) => $this->authorize('update', $t));
    }

    /** Runs a write on each target and reports each listing's own result. */
    private function eachTarget(array $ids, callable $write): JsonResponse
    {
        return response()->json(['data' => $this->targets($ids)->map(function (BusinessLocation $t) use ($write) {
            try {
                $write($t);

                return ['id' => $t->id, 'name' => $t->label(), 'ok' => true];
            } catch (Throwable $e) {
                report($e);

                return ['id' => $t->id, 'name' => $t->label(), 'ok' => false, 'message' => 'Google refused the change.'];
            }
        })->values()->all()]);
    }

    private function refused(): ?JsonResponse
    {
        try {
            BusinessProfileApi::writable();

            return null;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    private function live(callable $read): JsonResponse
    {
        try {
            return response()->json(['data' => $read()]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (Throwable) {
            return response()->json(['message' => 'Google did not answer for this listing just now. Try again later.'], Response::HTTP_BAD_GATEWAY);
        }
    }
}
