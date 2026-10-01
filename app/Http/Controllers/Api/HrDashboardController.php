<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Hr\HrOverview;
use Illuminate\Http\Request;

/**
 * The Human Resources dashboard chip (spec §12).
 *
 * One glance — the same HrGlance GET /dashboard would include for `hr`. Same
 * class, same resolve(), same stored presentation from `dashboard.glances`.
 * The chip does not apply `dashboard.order['hr']` (that surface order is gone;
 * there is only one box).
 *
 * Kept as its own route so opening Human Resources does not download every
 * module's glance.
 */
class HrDashboardController extends Controller
{
    public function __construct(private GlanceRegistry $registry) {}

    /**
     * GET /api/v1/hr/dashboard
     *
     * `viewAny` on User asks for `hr.view` — the tab permission, the same
     * question the Records tab asks. When find('hr') is null (kill switch or
     * permission narrowed since the role was written), answer with glance null
     * rather than 500.
     */
    public function index(Request $request): array
    {
        // The people policy, because that is what this tab summarises: a
        // dashboard of who works here is a view over the same records, and
        // giving it a gate of its own would be a second answer to one question.
        $this->authorize('viewAny', User::class);

        $user = $request->user();
        $period = $this->periodFor($user);

        $envelope = [
            'period' => $period->key,
            'period_starts_on' => $period->startsOn->toIso8601String(),
            'period_ends_on' => $period->endsOn->toIso8601String(),
        ];

        $glance = $this->registry->find('hr', $user);

        if (! $glance instanceof Glance) {
            return [...$envelope, 'glance' => null];
        }

        $presentation = $this->storedPresentation($glance, $user);
        $payload = $glance->resolve($user, $period, [])->toArray();
        $payload['presentation'] = $presentation;

        return [...$envelope, 'glance' => $payload];
    }

    /**
     * Chart kind from `dashboard.glances` for `hr`, if still allowed.
     *
     * Does not read `dashboard.order['hr']`. Order on this chip is meaningless
     * with one box; presentation is shared with the main board.
     */
    /**
     * GET /api/v1/hr/dashboard/overview?period=week|month|quarter|year — the
     * redesigned dashboard's one read (HrOverview). Without `period`, the saved
     * dashboard period, as index() uses.
     */
    public function overview(Request $request): array
    {
        $this->authorize('viewAny', User::class);

        $user = $request->user();
        $asked = $request->query('period');
        $period = is_string($asked) && in_array($asked, DashboardPeriod::VALUES, true)
            ? DashboardPeriod::for($user, $asked)
            : $this->periodFor($user);

        return ['data' => app(HrOverview::class)->for($user, $period)];
    }

    private function storedPresentation(Glance $glance, User $user): string
    {
        $allowed = $glance->allowedPresentations();
        $default = $glance->defaultPresentation();
        $saved = $user->preferences['dashboard.glances'] ?? null;

        if (! is_array($saved)) {
            return $default;
        }

        foreach ($saved as $entry) {
            if (($entry['key'] ?? null) !== 'hr') {
                continue;
            }

            $given = $entry['presentation'] ?? null;
            if (is_string($given) && in_array($given, $allowed, true)) {
                return $given;
            }

            break;
        }

        return $default;
    }

    private function periodFor(User $user): DashboardPeriod
    {
        $key = $user->preferences['dashboard.period'] ?? 'day';
        if (! in_array($key, DashboardPeriod::VALUES, true)) {
            $key = 'day';
        }

        return DashboardPeriod::for($user, $key);
    }
}
