<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebSite;
use App\Support\Web\Hours\HoursBatch;
use App\Support\Web\Hours\WeekEdit;
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
            'site_ids' => ['required', 'array', 'min:1', 'max:200'],
            'site_ids.*' => ['required', 'string', 'distinct'],
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

        $sites = WebSite::query()->whereIn('id', $input['site_ids'])->get();

        if ($sites->count() !== count($input['site_ids'])) {
            return response()->json(['message' => 'One of those websites is not in this company.'], 422);
        }

        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        $review = $batch->review(
            $sites->sortBy('host')->values(),
            $input['destinations'],
            $this->days($input['schedule']),
            $input['source_timezone'] ?? null,
            $input['not_before'] ?? null,
            (string) $request->user()->getKey(),
        );

        return response()->json(['data' => $review]);
    }

    /** POST /api/v1/web/hours/apply */
    public function apply(Request $request): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'max:200000']]);
        $user = $request->user();

        $result = HoursBatch::make()->apply(
            $input['token'],
            (string) $user->getKey(),
            function (string $id) use ($user): ?WebSite {
                $site = WebSite::query()->find($id);

                return $site !== null && Gate::forUser($user)->allows('update', $site) ? $site : null;
            },
        );

        if ($result === null) {
            return response()->json(['message' => 'That review has expired or is not yours. Review the changes again.'], 409);
        }

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
