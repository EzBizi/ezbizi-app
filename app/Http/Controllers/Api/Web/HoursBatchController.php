<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\BusinessLocation;
use App\Models\WebSite;
use App\Support\Audit\AuditLogger;
use App\Support\Web\Hours\BusinessProfileHours;
use App\Support\Web\Hours\HoursBatch;
use App\Support\Web\Hours\WebsiteHours;
use App\Support\Web\Hours\WeekEdit;
use App\Support\Web\SiteRefresh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The bulk hours editor: review a week edit across websites, then apply it.
 *
 * The request is the shape of Rodrigo's brief (2026-09-28): explicit actions,
 * never booleans, because a boolean cannot tell "keep what is saved" from
 * "open this day" from "close it".
 *
 *   {
 *     "site_ids": ["…"], "destinations": ["website"],
 *     "source_timezone": "America/New_York", "not_before": "06:00",
 *     "schedule": {
 *       "friday": {"action": "set_hours", "open": {"action": "keep"}, "close": {"action": "set", "time": "17:00"}},
 *       "saturday": {"action": "closed"}
 *     }
 *   }
 *
 * A day left out is Keep existing.
 *
 * Websites are named by `site_ids` (the "website" destination); Business
 * Profile locations by `location_ids` (Jose, 2026-10-16: chosen as locations,
 * not through a website's host). Each place is checked against its own
 * permission, and every applied place is audited.
 */
class HoursBatchController extends Controller
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    /** POST /api/v1/web/hours/review */
    public function review(Request $request): JsonResponse
    {
        $batch = HoursBatch::make();
        $input = $request->validate([
            'site_ids' => ['array', 'max:200'],
            'site_ids.*' => ['required', 'string', 'distinct'],
            'location_ids' => ['array', 'max:200'],
            'location_ids.*' => ['required', 'string', 'distinct'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*' => ['required', 'string', 'distinct', Rule::in(array_keys($batch->destinations()))],
            'source_timezone' => ['nullable', 'timezone:all'],
            'not_before' => ['nullable', self::TIME],
            'schedule' => ['present', 'array'],
            'schedule.*' => ['array'],
            'schedule.*.action' => ['required', Rule::in(['keep', 'set_hours', 'closed'])],
            'schedule.*.open.action' => ['sometimes', Rule::in(['keep', 'set'])],
            'schedule.*.open.time' => ['required_if:schedule.*.open.action,set', 'nullable', self::TIME],
            'schedule.*.close.action' => ['sometimes', Rule::in(['keep', 'set'])],
            'schedule.*.close.time' => ['required_if:schedule.*.close.action,set', 'nullable', self::TIME],
        ]);

        if (($unknown = array_diff(array_keys($input['schedule']), self::DAYS)) !== []) {
            return response()->json(['message' => 'Unknown day: '.implode(', ', $unknown).'.'], 422);
        }

        $targets = [];

        foreach ($input['destinations'] as $key) {
            $website = $key === WebsiteHours::KEY;
            $ids = $input[$website ? 'site_ids' : 'location_ids'] ?? [];

            if ($ids === []) {
                return response()->json(['message' => $website ? 'Choose the websites.' : 'Choose the Business Profile locations.'], 422);
            }

            $places = $website
                ? WebSite::query()->whereIn('id', $ids)->get()->sortBy('host')
                : BusinessLocation::query()->whereIn('id', $ids)->get()->sortBy('title');

            if ($places->count() !== count($ids)) {
                return response()->json(['message' => $website ? 'One of those websites is not in this organization.' : 'One of those locations is not in this organization.'], 422);
            }

            foreach ($places as $place) {
                $this->authorize('update', $place);
                $targets[] = [$key, $place];
            }
        }

        $review = $batch->review(
            $targets,
            $this->days($input['schedule']),
            $input['source_timezone'] ?? null,
            $input['not_before'] ?? null,
            (string) $request->user()->getKey(),
        );

        return response()->json(['data' => $review]);
    }

    /** POST /api/v1/web/hours/apply */
    public function apply(Request $request, AuditLogger $audit): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:200000']]);
        $user = $request->user();
        $found = [];

        $result = HoursBatch::make()->apply(
            $input['token'],
            (string) $user->getKey(),
            function (string $destination, string $id) use ($user, &$found): mixed {
                $place = ($destination === BusinessProfileHours::KEY ? BusinessLocation::query() : WebSite::query())->find($id);
                $place = $place !== null && Gate::forUser($user)->allows('update', $place) ? $place : null;

                return $found[$destination.':'.$id] = $place;
            },
        );

        if ($result === null) {
            return response()->json(['message' => 'That review has expired or is not yours. Review the changes again.'], 409);
        }

        // Every place, whatever became of it: who asked, what week, what the destination then held.
        foreach ($result['results'] as $row) {
            $audit->log('web.hours.'.$row['status'], $found[$row['destination'].':'.$row['id']] ?? null, metadata: [
                'destination' => $row['destination'], 'place' => $row['name'], 'reason' => $row['reason'], 'week' => $row['week'],
            ]);
        }

        // The websites that changed read their new hours now, not in five minutes.
        $changed = array_column(array_filter($result['results'], fn (array $r) => $r['status'] === 'changed' && $r['destination'] === WebsiteHours::KEY), 'id');
        SiteRefresh::later(...WebSite::query()->whereIn('id', $changed)->get()->all());

        return response()->json(['data' => $result]);
    }

    /** The brief's schedule as WeekEdit's seven days. */
    private function days(array $schedule): array
    {
        return array_map(function (string $name) use ($schedule): array {
            $day = $schedule[$name] ?? ['action' => 'keep'];
            $edge = fn (string $k) => ($day[$k]['action'] ?? 'keep') === 'set' ? $day[$k]['time'] : null;

            return match ($day['action']) {
                'closed' => ['action' => WeekEdit::CLOSED, 'open' => null, 'close' => null],
                'set_hours' => ['action' => WeekEdit::SET, 'open' => $edge('open'), 'close' => $edge('close')],
                default => ['action' => WeekEdit::KEEP, 'open' => null, 'close' => null],
            };
        }, self::DAYS);
    }
}
