<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\MailboxGrant;
use App\Models\Onboarding;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\TwoFactorPolicy;
use App\Support\I18n\UiCatalogue;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Monitoring\ScreenMonitoringExemption;
use App\Support\Preferences\PreferenceRegistry;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\DisplaySettings;
use App\Support\TimeClock\TimeClock;
use App\Support\ViewAs\ViewAs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me: who am I, what the client calls on launch to know what to render.
 * Moved out of routes/api.php (2026-11-23) so View as can answer its start
 * and stop with the same payload.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::payload($request, $request->user())]);
    }

    public static function payload(Request $request, User $user): array
    {
        // Resolved once and used twice, below: the client is told where it
        // is standing AND what that place's settings say, and the two must
        // describe the same place or the dates on screen belong to a scope
        // the top bar is not naming.
        $view = ViewScope::forUser($user);

        // Asked twice below — for the keys and for the modules — and both
        // answers have to come from the same reading of the platform flags.
        $catalogue = app(PermissionCatalogue::class);

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => ['id' => $user->role?->id, 'name' => $user->role?->name],

            /*
                 * WHAT this person may do, and WHICH modules exist for them
                 * (§4). Both come from PermissionCatalogue, which derives
                 * them from the module registry — so a module added next
                 * year appears here without this file being touched.
                 *
                 * `permissions` is the exact set `User::hasPermission()`
                 * would answer true for, key by key. Anything looser and the
                 * client draws a control the server then refuses.
                 *
                 * For an Administrator it is the full catalogue, NOT a
                 * wildcard: a client that special-cases a role name is the
                 * same bug this endpoint's own server side had, where
                 * identity was the English word "Administrator" and a rename
                 * silently revoked everything.
                 *
                 * `modules` carries the two gates separately —
                 * `available` is EzBizi's (§13 entitlement), `reachable` is
                 * this organization's own decision about this role. A screen
                 * can then say "your plan does not include this" rather than
                 * quietly omitting a destination and leaving somebody
                 * looking for a menu item that was described to them on the
                 * phone.
                 */
            'permissions' => $catalogue->heldBy($user),
            'modules' => $catalogue->modulesFor($user),

            // The role's phone bottom bar, minus slots this person cannot
            // reach now; null = never set, the client draws its default.
            'mobile_bar' => app(\App\Support\Roles\MobileBar::class)->forUser($user),

            // Whether there is a photo to fetch, so the top bar can ask
            // for one only when there is one. `/me/profile` already
            // carried this, but the top bar reads THIS payload — so
            // without it the bar either requests an avatar for everybody
            // who has none, or never requests one at all. It was the
            // latter, and the picture only ever appeared on the profile
            // screen that had just uploaded it.
            'has_avatar' => $user->avatar_path !== null,
            // What the profile still lacks, so the top bar can pulse
            // the picture until it is complete (Jose, 2026-09-30, from
            // A1 Tools). Keys, never values. User::PROFILE_FIELDS.
            'profile_missing' => $user->profileMissing(),
            // Carried here as well as on their own endpoint, so the app
            // can paint the right theme on the first frame instead of
            // flashing the wrong one while a second request lands.
            //
            // This used to be wrapped in
            // OrganizationCanvasController::cleanedInto, because the
            // structure canvas was a per-user preference and this was
            // one of the doors it left through. It is an organization
            // setting now — it rides on GET /companies with the tree it
            // describes, and it is cleaned there — and the old key is
            // stripped from every user by the migration that retired
            // it, so there is nothing here left to clean.
            'preferences' => array_merge(
                app(PreferenceRegistry::class)->defaults(),
                $user->preferences ?? [],
            ),
            // Whether this person is also platform staff (§4.2, revised).
            // The client renders the Development section from this, so it
            // cannot appear for somebody with no staff account behind them.
            'development' => (function () use ($user) {
                $staff = PlatformStaffLink::staffFor($user->getKey());

                return $staff === null ? null : [
                    'tier' => $staff->tier,
                    'staff_name' => $staff->name,
                    'focused_at' => (string) config('ezbizi.hosts.staff'),
                ];
            })(),
            // The organization requires a second factor and this account
            // has none (§6). Carried here as well as on the login body
            // because a page refresh restores the session from a stored
            // token and never sees a login body — without it the app
            // would come back with every screen quietly answering 403
            // and nothing on screen saying why.
            'two_factor_enrolment_required' => app(TwoFactorPolicy::class)->mustEnrol($user),

            // The unfinished onboarding this person is the SUBJECT of:
            // the client draws "Your paperwork" from it (session.dart
            // onboardingId). Without it a new starter whose role has no
            // HR tab had no way to reach their own form.
            // `held`: everything else is closed until the paperwork
            // is approved (User::awaitingOnboardingApproval).
            'onboarding' => ($open = Onboarding::query()
                ->where('user_id', $user->id)
                ->whereIn('status', Onboarding::OPEN)
                ->latest()
                ->first(['id', 'status'])) === null ? null : [
                    'id' => $open->id,
                    'status' => $open->status,
                    'held' => $user->awaitingOnboardingApproval(),
                ],

            // This address has not been confirmed, so RequireVerifiedEmail
            // refuses every write (TODO #22). Carried here for exactly the
            // reason two_factor_enrolment_required above is: a refresh
            // restores the session from a stored token and never sees a
            // login body, so without it the app returns with every save
            // answering 403 and nothing on screen saying why.
            //
            // A boolean rather than the timestamp, following has_avatar:
            // the client's question is whether to show the banner, and a
            // date would make it derive that itself in a second place.
            //
            // Staff are never gated - the middleware exempts anything that
            // is not a User - so this is false for them and the banner
            // cannot appear on a staff session that could not act on it.
            'email_confirmation_required' => $user->email_verified_at === null,

            // SECURITY exemption (Monitoring): the Lead Developer's own
            // device is never screen-captured, live-viewed or
            // remote-controlled. The desktop agent reads this to skip
            // constructing its capture services and to omit the
            // `screenshot` capability from its heartbeat — the server
            // strips it regardless, this just stops the agent declaring
            // it. Keyed on the shared email between this org user and an
            // active lead-developer staff account; see
            // ScreenMonitoringExemption.
            'screen_monitoring_exempt' => ScreenMonitoringExemption::isExempt($user),

            // Clock in chrome on the first frame (design §8). The top-bar
            // button reads this rather than a second round trip.
            'time_clock' => app(TimeClock::class)->payload($user),

            // What the change-password form should ASK for: the platform
            // floor, or this organization's own minimum where that is
            // higher (§6). The client used to state a constant it kept
            // in Dart, which was right until an administrator raised the
            // setting and then told everybody the wrong number.
            //
            // Signup and password-reset cannot be told this — neither has
            // a session, and reset has no organization until the token is
            // examined server-side — so those two screens still state the
            // platform floor and the server corrects them. See
            // client/lib/core/password_policy.dart.
            'password_min_length' => PasswordPolicy::minLengthFor(
                $user->organization,
            ),
            'organization' => [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'address' => $user->organization->address,
                'address_line1' => $user->organization->address_line1,
                'address_line2' => $user->organization->address_line2,
                'city' => $user->organization->city,
                'state' => $user->organization->state,
                'postal_code' => $user->organization->postal_code,
                'franchise_label' => $user->organization->franchise_label,
                'is_read_only' => $user->organization->isReadOnly(),
                // Whether the organization view is offered: only to
                // somebody assigned at organization level (2026-10-16).
                'can_view' => $user->canActOnOrganization($user->organization),
            ],
            // Reach, so the client knows which franchises to offer —
            // rather than showing all and failing on save (§4).
            // The companies behind those franchises. §6 allows settings
            // at company level and the client had no way to select one,
            // so a whole tier of the cascade was configured through the
            // API or not at all.
            'companies' => $user->reachableFranchises()
                ->pluck('company')
                ->filter()
                ->unique('id')
                ->values()
                // The parent comes too. Without it the client can only
                // draw companies as one flat row, which renders a Sub
                // Company as a sibling of the company it belongs to —
                // and there is no way to tell from the screen that it
                // inherits from that company rather than from the
                // organization.
                ->pipe(function ($companies) {
                    // Off under a parent that is off too: that parent
                    // is not in this list when it has no branches of
                    // its own, so the client could not tell.
                    $off = Company::query()->whereKey($companies->pluck('id'))
                        ->switchedOff()->pluck('id')->all();

                    return $companies->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'parent_company_id' => $c->parent_company_id,
                        'has_logo' => $c->logo_path !== null,
                        // Switched off: kept in reach so a record still
                        // reads its name, offered nowhere (Jose,
                        // 2026-10-15 and 11-10).
                        'is_active' => ! in_array($c->id, $off, true),
                    ]);
                })
                ->all(),

            // The RESOLVED scope, not the stored string. A preference
            // that has stopped being valid — a franchise removed, an
            // assignment narrowed — must not come back as the client's
            // idea of where it is.
            'scope' => $view->toArray(),

            // How this scope writes a date and a clock (§6).
            //
            // Here rather than on the settings endpoint because every
            // screen needs them and only one screen calls /settings.
            // /me is already fetched on launch and again on every scope
            // change — Session.setScope() refetches it, precisely
            // because `scope` above is resolved server-side — so the
            // formats arrive with the view they belong to and change
            // when it does, with nothing extra to keep in step.
            //
            // The client formats FROM these instead of from the
            // browser's locale. That is the whole point: an owner who
            // sets 24h must get 24h on a machine set to US English,
            // and until this existed there was no resolved setting on
            // the client at all for any screen to read.
            'display' => app(DisplaySettings::class)
                ->forScope($view->settingsScope()),

            ...app(UiCatalogue::class)->forUser($user),

            'franchises' => $user->reachableFranchises()->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'company_id' => $f->company_id,
                'is_active' => $f->is_active,
            ])->values(),
        ];

        // View as (Jose, 2026-11-23). `view_as_available` is about the REAL
        // user, so the icon stays while viewing; `viewing_as` drives the banner.
        $data['view_as_available'] = ViewAs::allowed(ViewAs::realUser($request) ?? $user);
        $data['viewing_as'] = ViewAs::mePayload($user);

        // What this person may reach, as one value. An open client asks
        // /me every minute and on resume, and redraws the screen when this
        // moves (Jose, 2026-11-10: a granted inbox showed only after a
        // reload). Inboxes are named by grant, because a second inbox
        // changes no permission key.
        $data['access_version'] = sha1(json_encode([
            $data['role'],
            $data['permissions'],
            $data['modules'],
            $data['companies'],
            $data['franchises'],
            MailboxGrant::query()
                ->where('user_id', $user->ownRowsKey())
                ->when($user->role_id, fn ($q) => $q->orWhere('role_id', $user->role_id))
                ->orderBy('mailbox_id')
                ->pluck('mailbox_id')
                ->all(),
        ]));

        return $data;
    }
}
