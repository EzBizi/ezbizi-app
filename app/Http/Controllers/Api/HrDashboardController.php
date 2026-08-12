<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Dashboard\Widget;
use App\Support\Dashboard\WidgetRegistry;
use App\Support\Dashboard\Widgets\Hr\Headcount;
use App\Support\Dashboard\Widgets\Hr\InvitationsStalled;
use App\Support\Dashboard\Widgets\Hr\OnboardingsInFlight;
use App\Support\Dashboard\Widgets\Hr\StartersAndLeavers;
use Illuminate\Http\Request;

/**
 * The Human Resources dashboard (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * ── Why this is four lines of list and not a second dashboard ────
 *
 * §18 named the widget registry as the pattern for exactly this, and the figures
 * on this tab are widgets: a question, a permission, and an answer computed under
 * the caller's reach. So they are declared in WidgetRegistry alongside the rest
 * and this controller does nothing but say WHICH of them the tab shows and in
 * what order.
 *
 * The alternative — an endpoint that computed headcount itself — would have been
 * shorter to write and would have created the trap §6 is about: the same figure
 * arrived at two ways, drifting the first time one of them was corrected.
 *
 * ── Fixed SELECTION, chosen order ────────────────────────────────
 *
 * The main dashboard is a preference twice over: you choose which panels you
 * have and what sequence they sit in (§2.1 category 6). This tab is a
 * preference once. WHICH panels appear is fixed — PANELS below is a constant,
 * and everybody with `hr.view` gets the same four — because the HR plan names
 * five things this dashboard is for and an owner opening Human Resources for
 * the first time should see them without having to assemble anything. The same
 * widgets stay available on the main dashboard for anybody who wants headcount
 * beside their revenue.
 *
 * The ORDER is theirs. Dragging a card on this tab saves the sequence under
 * `dashboard.order['hr']` — DashboardController::ORDERABLE, its saveOrder(),
 * the same endpoint and the same preferences store the main layout uses. That
 * reuse is why the widgets did not have to be rebuilt to get it.
 *
 * This endpoint knows nothing about that order and deliberately does not: it
 * always answers in the plan's sequence and the CLIENT rearranges (see
 * `panelsInSavedOrder`). A saved order names panels this endpoint never sends —
 * "Expiring certifications" is drawn client-side and has no widget — so the
 * server is the wrong place to apply it.
 */
class HrDashboardController extends Controller
{
    public function __construct(private WidgetRegistry $registry) {}

    /**
     * The panels, in the order the plan names them.
     *
     * Class constants rather than bare strings: the key is the thing stored in a
     * layout and returned on the wire, so it exists in one place and both the
     * widget and this list read it from there.
     *
     * "Expiring certifications" is absent, and its absence is a decision. A
     * contract template's fields (App\Support\Contracts\FieldDefinition) carry
     * key, label, type, filled_by, required, sensitive and retired — and no
     * expiry. A `date` field can hold one, but nothing marks a date AS an expiry,
     * so there is no way to ask "which certifications lapse this month" without
     * first inventing a seventh field property that onboarding, the diff, the
     * renderer and every stored version would then have to match. That is a
     * contract-template decision, not a dashboard one; it is recorded as
     * EZBIZI-TODO.md #26 and the panel stays out until the field model can
     * answer. The client draws the card badged "Not built yet" and says what it
     * waits for, rather than the tab quietly having one fewer panel than the
     * plan names.
     */
    private const PANELS = [
        Headcount::KEY,
        OnboardingsInFlight::KEY,
        InvitationsStalled::KEY,
        StartersAndLeavers::KEY,
    ];

    /**
     * GET /api/v1/hr/dashboard
     *
     * Two gates (§4). The route sits behind authentication, `viewAny` asks for
     * `hr.view` — the tab permission, the same question the Records tab asks —
     * and the registry then filters every widget by its own permission before it
     * can be resolved. The second is not redundant: a widget added to the HR list
     * later with a narrower permission is filtered by the registry without this
     * controller having to remember it exists.
     *
     * No widget in PANELS needs it yet — all four answer `hr.view`, which is
     * what the route already asked — so on THIS tab the second gate is
     * unexercised rather than proven. Where it is exercised is the catalogue,
     * which any signed-in caller may read: HrDashboardTest
     * ::test_the_registry_withholds_hr_widgets_from_somebody_without_hr_view.
     */
    public function index(Request $request): array
    {
        // The people policy, because that is what this tab summarises: a
        // dashboard of who works here is a view over the same records, and
        // giving it a gate of its own would be a second answer to one question.
        $this->authorize('viewAny', User::class);

        $user = $request->user();

        $panels = [];

        foreach (self::PANELS as $key) {
            $widget = $this->registry->find($key, $user);

            // Skipped rather than errored, for the reason DashboardController
            // gives: a module switched off platform-wide, or a permission
            // narrowed since the role was written, should make this tab show
            // less rather than refuse to load.
            if (! $widget instanceof Widget) {
                continue;
            }

            $panels[] = [
                'key'   => $widget->key(),
                'label' => $widget->label(),

                // Defaults. This tab has no per-user options to echo back — see
                // the class docblock on why its SELECTION is fixed rather than
                // composed. What is per-user here is the order, and the client
                // applies that.
                'data'  => $widget->resolve($user, []),
            ];
        }

        return ['panels' => $panels];
    }
}
