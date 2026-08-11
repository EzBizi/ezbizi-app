<?php

use App\Http\Controllers\Api\AttachmentsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationsController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\EnrolmentController;
use App\Http\Controllers\Api\FranchisesController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HrContractsController;
use App\Http\Controllers\Api\HrInvitationsController;
use App\Http\Controllers\Api\HrOnboardingsController;
use App\Http\Controllers\Api\HrPeopleController;
use App\Http\Controllers\Api\InvitationAcceptanceController;
use App\Http\Controllers\Api\JobsController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PreferencesController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StaffAccountsController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioned from the first endpoint (plan §11.4). Adding /v1 later means
| either breaking every client or maintaining an unversioned alias forever —
| the cost now is one path segment.
|
| The middleware ORDER matters:
|
|   auth:sanctum   who is this?
|   tenant         which organization? (from the USER, never the request)
|   subscription   may they write? (§13)
|   two-factor     has the organization made a second factor mandatory? (§6)
|   idle           keep the inactivity window open (§6)
|
| Tenant must come after auth — there is no organization before there is a
| user. Subscription must come after tenant, since it reads the organization.
|
| The last two read settings, and both enter the caller's organization
| themselves rather than relying on `tenant` having run first — the framework
| sorts this list against its own priority map, so a list position is a
| statement of intent and not a guarantee.
|
| `two-factor` refuses nothing under /me or /auth: the way OUT of the refusal is
| the enrolment screen, and a control somebody cannot reach is a lockout. See
| RequireTwoFactorEnrolment.
|
| `idle` refuses nothing at all. It moves each token's expiry forward, and
| Sanctum does the refusing when it stops moving. See SlideIdleWindow.
|
*/

// ── Public: no user and no organization exist yet ────────────────
Route::prefix('v1/auth')->group(function () {
    // The front door (§3, TODO #10). One request, one transaction: the person,
    // the organization, its first company and that company's first franchise.
    //
    // The only route in this file carrying `throttle`, and it needs it more than
    // any of the others — it is public, unauthenticated, and every success
    // writes six rows. See AppServiceProvider::defineRateLimiters for the
    // numbers and what they do and do not cover.
    Route::post('signup', [AuthController::class, 'signup'])
        ->middleware('throttle:signup');

    Route::post('login',  [AuthController::class, 'login']);
    Route::post('choose', [AuthController::class, 'choose']);

    // Public by necessity — somebody who has forgotten their password cannot
    // sign in to ask. Both answer identically whatever the outcome, so neither
    // can be used to discover which addresses are registered.
    Route::post('forgot', [PasswordController::class, 'forgot']);
    Route::post('reset',  [PasswordController::class, 'reset']);
});

/*
|--------------------------------------------------------------------------
| Accepting an invitation — public, and the second front door (§4, HR plan)
|--------------------------------------------------------------------------
|
| Outside /auth because it is not authentication: it is somebody who has never
| had an account being handed one. It ends in a session all the same, and the
| POST answers exactly what `login` answers, so the client can treat it as a
| sign-in.
|
| No `auth:sanctum`, no `tenant`, no `subscription` middleware — there is no user
| and no session, and the TOKEN is the only thing that names an organization.
| TenantScope throws with no tenant set, so the controller establishes tenancy
| itself, the way AuthController::signup does. See InvitationAcceptanceController.
|
| THE TOKEN IS IN THE BODY, NOT THE PATH — and it was in the path first.
|
| nginx logs the request line, so `GET /api/v1/invitations/<token>` wrote every
| invitation credential into /var/log/nginx/app.ezbizi.com.access.log, kept for
| a fortnight and copied into anything that ships those logs. The same URL then
| went out as a `Referer` on the XHR the acceptance page fires, and was logged
| again. Nothing about the token being hashed at rest helps once the raw value is
| sitting in a text file.
|
| `POST /lookup` for a read is not REST, and that is the smaller price.
| PasswordController::reset already carries its token in the JSON body for
| exactly this reason, so this now matches the one precedent in the codebase
| rather than departing from it. The token still travels in the URL of the LINK
| somebody clicks — it has to, that is what a link is — but the browser only ever
| sends it to the client bundle, and the API call it triggers keeps it in a body.
|
| No route model binding: a bound {invitation} would run a tenant-scoped read
| with no tenant, and a token is not a primary key. Looking up a credential
| belongs in the controller.
|
| Throttled, and the only routes outside /auth that are. Both share one named
| limiter because they are one visit; see AppServiceProvider for the number and
| what it does and does not cover.
|
*/
Route::prefix('v1/invitations')->middleware('throttle:invitation')->group(function () {
    Route::post('lookup', [InvitationAcceptanceController::class, 'show']);
    Route::post('accept', [InvitationAcceptanceController::class, 'accept']);
});

// ── Authenticated ────────────────────────────────────────────────
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant', 'subscription', 'two-factor', 'idle'])
    ->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);

        // ── The vertical slice: Customer → Job (§18 Phase 2) ─────
        //
        // Customers are shared org-wide and jobs are not, so the two look
        // deliberately different: the customer list is unfiltered, the job list
        // is scoped to the caller's reach. Both are enforced in policies, not
        // here — a route file is a bad place to keep an authorization rule,
        // because the next endpoint added beside it will not inherit the thought.
        Route::apiResource('customers', CustomersController::class);
        Route::post('customers/{customer}/owner', [CustomersController::class, 'reassignOwner']);

        Route::apiResource('jobs', JobsController::class);

        // ── The shape of the business (§3) ───────────────────────
        //
        // The model has carried Sub Companies since the foundation and the
        // settings resolver has walked through them, with tests — but nothing
        // could create one. Structure was editable by whoever had a shell on
        // the box and by nobody else.
        //
        // Listing is on the companies route because it returns the whole tree:
        // assembling that shape from two flat lists is a second place for it to
        // be decided, and to be decided differently.
        Route::get('companies', [CompaniesController::class, 'index']);
        Route::post('companies', [CompaniesController::class, 'store']);
        Route::patch('companies/{company}', [CompaniesController::class, 'update']);

        // ── Branding (§12) ───────────────────────────────────────
        //
        // Served through a controller, never as a public file: a logo at a
        // guessable path enumerates every organization on the platform.
        // Franchises have none — a franchise is a geography, not a brand.
        Route::get('branding/shapes', [BrandingController::class, 'shapes']);
        Route::post('branding/organization', [BrandingController::class, 'uploadOrganization']);
        Route::delete('branding/organization', [BrandingController::class, 'destroyOrganization']);
        Route::get('branding/organization', [BrandingController::class, 'organization']);

        Route::post('branding/companies/{company}', [BrandingController::class, 'uploadCompany']);
        Route::delete('branding/companies/{company}', [BrandingController::class, 'destroyCompany']);
        Route::get('branding/companies/{company}', [BrandingController::class, 'company']);

        Route::post('franchises', [FranchisesController::class, 'store']);
        Route::patch('franchises/{franchise}', [FranchisesController::class, 'update']);

        // ── Roles & permissions (§4) ─────────────────────────────
        //
        // The half of the access model that did not exist: nothing could create
        // a second role, so every tenant was permanently one Administrator and
        // every permission the software defines was ungrantable.
        //
        // `catalogue` is declared first out of habit rather than necessity —
        // there is no GET roles/{role} for it to collide with today, and the day
        // somebody adds one it must already be above it.
        Route::get('roles/catalogue', [RolesController::class, 'catalogue']);
        Route::get('roles', [RolesController::class, 'index']);
        Route::post('roles', [RolesController::class, 'store']);
        Route::patch('roles/{role}', [RolesController::class, 'update']);
        Route::delete('roles/{role}', [RolesController::class, 'destroy']);

        // ── Human Resources (§4, EZBIZI-HR-PLAN.md) ──────────────
        //
        // The module that lets an organization have employees at all. Until it
        // existed nothing wrote `users.role_id`, so every role an organization
        // composed had nobody to give it to.
        //
        // `people/scope-options` is declared BEFORE `people/{user}` out of the
        // same habit `roles/catalogue` follows. There is no collision today —
        // the literal is a GET and the parameter route is a PATCH — and the day
        // somebody adds `GET people/{user}` it must already be above it.
        Route::get('hr/people/scope-options', [HrPeopleController::class, 'scopeOptions']);
        Route::get('hr/people', [HrPeopleController::class, 'index']);
        Route::patch('hr/people/{user}', [HrPeopleController::class, 'update']);

        Route::get('hr/invitations', [HrInvitationsController::class, 'index']);
        Route::post('hr/invitations', [HrInvitationsController::class, 'store']);
        // Resend and revoke, one at a time. The owner was explicit that there is
        // no bulk form of either, and there is no route here that could become
        // one without somebody adding it deliberately.
        Route::post('hr/invitations/{invitation}/resend', [HrInvitationsController::class, 'resend']);
        Route::delete('hr/invitations/{invitation}', [HrInvitationsController::class, 'destroy']);

        // The Contracts tab — the template designer, and the schema every other
        // HR tab is built on (EZBIZI-HR-PLAN.md).
        //
        // `catalogue` sits BEFORE `{contract}` or it binds as a contract id and
        // 404s — the same trap `automations/catalogue` documents.
        //
        // There is no DELETE. A template is what produced somebody's employment
        // record; removing it would leave that record describing questions
        // nothing can reproduce. Fields are retired rather than deleted, and a
        // template that is no longer used is simply not chosen.
        Route::get('hr/contracts/catalogue', [HrContractsController::class, 'catalogue']);
        Route::get('hr/contracts', [HrContractsController::class, 'index']);
        Route::post('hr/contracts', [HrContractsController::class, 'store']);
        Route::get('hr/contracts/{contract}/versions', [HrContractsController::class, 'versions']);
        // Publishing is its own act with its own diff — see the controller for
        // why PATCH refuses a field list rather than accepting one.
        Route::post('hr/contracts/{contract}/versions', [HrContractsController::class, 'publishVersion']);
        Route::get('hr/contracts/{contract}', [HrContractsController::class, 'show']);
        Route::patch('hr/contracts/{contract}', [HrContractsController::class, 'update']);

        // ── Onboarding, the tab the rest of HR was built for ─────
        //
        // The invitation produces the person, the contract produces the
        // questions, and this renders one against the other: the two-sided
        // form, the answers with their provenance, the signature and the
        // document.
        //
        // The list is NOT gated on `hr.view` — see the controller. A new starter
        // holds no HR permission at all and their own form is the one screen in
        // this module they must reach, so the list is filtered rather than
        // refused.
        //
        // Two acts here are deliberately not PATCH, for the reason
        // hr/contracts/{contract}/versions is not either: signing an agreement
        // and amending a signed one are separate, consequential acts, and an
        // endpoint that also saves answers would let somebody do either by
        // pressing save.
        Route::get('hr/onboardings', [HrOnboardingsController::class, 'index']);
        Route::post('hr/onboardings', [HrOnboardingsController::class, 'store']);
        Route::get('hr/onboardings/{onboarding}', [HrOnboardingsController::class, 'show']);
        Route::patch('hr/onboardings/{onboarding}', [HrOnboardingsController::class, 'update']);
        Route::post('hr/onboardings/{onboarding}/sign', [HrOnboardingsController::class, 'sign']);
        Route::post('hr/onboardings/{onboarding}/amendments', [HrOnboardingsController::class, 'amend']);
        // The rendered document and its fingerprint — a preview before anything
        // is signed, the stored bytes afterwards. The FILE is downloaded through
        // `GET attachments/{id}` like every other file on the platform; this
        // answers with the content and the hash beside it, because the caller's
        // next act is to sign and it has to send back the fingerprint of exactly
        // what it received.
        Route::get('hr/onboardings/{onboarding}/document', [HrOnboardingsController::class, 'document']);
        // Revealing one sensitive answer. Its own endpoint because ASKING is the
        // reveal: every other read of this record answers the mask, so each entry
        // in the log means somebody deliberately looked rather than that a screen
        // was opened.
        Route::get('hr/onboardings/{onboarding}/answers/{field}',
            [HrOnboardingsController::class, 'revealAnswer']);

        // ── Attachments (§9, §11) ────────────────────────────────
        //
        // One endpoint for every record type that can carry a file, rather than
        // a nested collection per module. The subject arrives in the body and is
        // resolved through App\Support\Files\Attachables, which is also where
        // the authorization comes from: a file is exactly as private as the
        // thing it hangs off, so there is no `attachments.*` permission and
        // there must not be one.
        //
        // `GET attachments/{attachment}` answers BYTES, not JSON, and never a
        // public path — a guessable URL to somebody's licence is the §9 failure
        // this whole primitive has to avoid. The list is the query-string form
        // above it, which is what keeps the two from colliding.
        Route::get('attachments', [AttachmentsController::class, 'index']);
        Route::post('attachments', [AttachmentsController::class, 'store']);
        Route::get('attachments/{attachment}', [AttachmentsController::class, 'show']);
        Route::delete('attachments/{attachment}', [AttachmentsController::class, 'destroy']);

        // ── Dashboard (§18 Phase 4) ──────────────────────────────
        //
        // `catalogue` before nothing in particular — there is no {id} route to
        // collide with — but kept adjacent so the three read as one feature.
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/catalogue', [DashboardController::class, 'catalogue']);
        Route::put('dashboard/layout', [DashboardController::class, 'saveLayout']);

        // ── Import / export (§12, §9) ────────────────────────────
        //
        // Under /exports because EnforceSubscriptionState exempts that prefix by
        // pattern: §13 requires export to keep working after a lapse. Imports get
        // their own prefix precisely so they do NOT inherit that exemption.
        //
        // `bundle` is declared before `{resource}` or it binds as one and 404s.
        // `inventory` is the §9 data map — what personal data is held, in which
        // table, and why — and sits beside the other two §9 answers rather than
        // in a module of its own. Same rule about declaration order applies.
        Route::get('exports', [DataController::class, 'catalogue']);
        Route::get('exports/bundle', [DataController::class, 'bundle']);
        Route::get('exports/inventory', [DataController::class, 'inventory']);
        Route::get('exports/{resource}', [DataController::class, 'export']);
        Route::get('customers/{customer}/export', [DataController::class, 'person']);

        Route::post('imports/{resource}', [DataController::class, 'import']);

        // ── Automations (§5.5) ───────────────────────────────────
        //
        // The catalogue route sits BEFORE the resource, or /automations/catalogue
        // binds as an automation id and 404s.
        Route::get('automations/catalogue', [AutomationsController::class, 'catalogue']);
        Route::get('automations/{automation}/runs', [AutomationsController::class, 'runs']);
        Route::post('automations/{automation}/test', [AutomationsController::class, 'test']);
        Route::post('automations/{automation}/activate/{version}', [AutomationsController::class, 'activateVersion']);
        Route::apiResource('automations', AutomationsController::class)->except(['store'])
            ->parameters(['automations' => 'automation']);
        Route::post('automations', [AutomationsController::class, 'store']);

        // Changing it while signed in. Needs the current password: a session
        // proves somebody sat down at an unlocked laptop, not that they are the
        // account holder.
        Route::post('me/password', [PasswordController::class, 'change']);

        // ── Your own account (§2.1 category 6) ───────────────────
        //
        // No policy on any of these: each is bound to the signed-in user's own
        // record, so there is nobody else to authorize against.
        Route::get('me/profile', [ProfileController::class, 'show']);
        Route::patch('me', [ProfileController::class, 'update']);

        // Pictures are served through a controller, never as a public file. A
        // guessable avatar URL leaks who works where, across every tenant.
        Route::post('me/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('me/avatar', [ProfileController::class, 'destroyAvatar']);
        Route::get('users/{user}/avatar', [ProfileController::class, 'avatar']);
        Route::get('me/sessions', [ProfileController::class, 'sessions']);
        Route::delete('me/sessions', [ProfileController::class, 'revokeOtherSessions']);

        // ── Your own preferences (§2.1 category 6) ───────────────
        //
        // Kept apart from /settings on purpose. Those are the organization's,
        // set by an administrator; these are the person's. §2.1 is explicit that
        // the categories must not be merged into one toggle system.
        Route::get('me/preferences', [PreferencesController::class, 'index']);
        Route::patch('me/preferences', [PreferencesController::class, 'update']);

        // ── Your own second factor (§6) ──────────────────────────
        //
        // Self-service on purpose: anybody signed in can switch it on without an
        // administrator, and without anyone from EzBizi being available. No
        // policy — every route is bound to the signed-in user's own record.
        Route::get('me/totp', [TwoFactorController::class, 'show']);
        Route::post('me/totp', [TwoFactorController::class, 'begin']);
        Route::post('me/totp/confirm', [TwoFactorController::class, 'confirm']);
        Route::delete('me/totp', [TwoFactorController::class, 'disable']);

        // ── Notifications (§12) ──────────────────────────────────
        //
        // No policy: every query is bound to the signed-in user's own id, so
        // there is nothing to authorize beyond being signed in.
        Route::get('notifications', [NotificationsController::class, 'index']);
        Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationsController::class, 'markRead']);

        // ── Settings (§6) ────────────────────────────────────────
        Route::get('settings', [SettingsController::class, 'index']);
        Route::patch('settings/{key}', [SettingsController::class, 'update']);
        Route::delete('settings/{key}', [SettingsController::class, 'destroy']);

        // Who am I — what the client calls on launch to know what to render.
        // (staff routes are below, deliberately outside this group)
        Route::get('me', function (Illuminate\Http\Request $request) {
            $user = $request->user();

            // Resolved once and used twice, below: the client is told where it
            // is standing AND what that place's settings say, and the two must
            // describe the same place or the dates on screen belong to a scope
            // the top bar is not naming.
            $view = \App\Support\Scoping\ViewScope::forUser($user);

            // Asked twice below — for the keys and for the modules — and both
            // answers have to come from the same reading of the platform flags.
            $catalogue = app(\App\Support\Modules\PermissionCatalogue::class);

            return response()->json([
                'data' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => ['id' => $user->role?->id, 'name' => $user->role?->name],

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
                    'modules'     => $catalogue->modulesFor($user),

                    // Whether there is a photo to fetch, so the top bar can ask
                    // for one only when there is one. `/me/profile` already
                    // carried this, but the top bar reads THIS payload — so
                    // without it the bar either requests an avatar for everybody
                    // who has none, or never requests one at all. It was the
                    // latter, and the picture only ever appeared on the profile
                    // screen that had just uploaded it.
                    'has_avatar' => $user->avatar_path !== null,
                    // Carried here as well as on their own endpoint, so the app
                    // can paint the right theme on the first frame instead of
                    // flashing the wrong one while a second request lands.
                    'preferences' => array_merge(
                        app(\App\Support\Preferences\PreferenceRegistry::class)->defaults(),
                        $user->preferences ?? [],
                    ),
                    // Whether this person is also platform staff (§4.2, revised).
                    // The client renders the Development section from this, so it
                    // cannot appear for somebody with no staff account behind them.
                    'development' => (function () use ($user) {
                        $staff = \App\Models\PlatformStaffLink::staffFor($user->getKey());

                        return $staff === null ? null : [
                            'tier'       => $staff->tier,
                            'staff_name' => $staff->name,
                            'focused_at' => 'https://dev.ezbizi.com',
                        ];
                    })(),
                    // The organization requires a second factor and this account
                    // has none (§6). Carried here as well as on the login body
                    // because a page refresh restores the session from a stored
                    // token and never sees a login body — without it the app
                    // would come back with every screen quietly answering 403
                    // and nothing on screen saying why.
                    'two_factor_enrolment_required' =>
                        app(\App\Support\Auth\TwoFactorPolicy::class)->mustEnrol($user),

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
                    'password_min_length' => \App\Support\Auth\PasswordPolicy::minLengthFor(
                        $user->organization,
                    ),
                    'organization' => [
                        'id'              => $user->organization->id,
                        'name'            => $user->organization->name,
                        'franchise_label' => $user->organization->franchise_label,
                        'is_read_only'    => $user->organization->isReadOnly(),
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
                        ->map(fn ($c) => [
                            'id'                => $c->id,
                            'name'              => $c->name,
                            'parent_company_id' => $c->parent_company_id,
                            'has_logo'          => $c->logo_path !== null,
                        ])
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
                    'display' => app(\App\Support\Settings\DisplaySettings::class)
                        ->forScope($view->settingsScope()),

                    'franchises' => $user->reachableFranchises()->map(fn ($f) => [
                        'id'         => $f->id,
                        'name'       => $f->name,
                        'company_id' => $f->company_id,
                        'is_active'  => $f->is_active,
                    ])->values(),
                ],
            ]);
        });
    });

/*
|--------------------------------------------------------------------------
| Platform control plane — the Development tab (§4.2)
|--------------------------------------------------------------------------
|
| A separate identity domain, so a separate middleware stack: no `tenant`, no
| `subscription`. Staff work across organizations, and there is no single one to
| put in context — TenantContext enters PLATFORM SCOPE instead, which makes an
| accidental unscoped query distinguishable from legitimate staff access.
|
| `staff` does the real gating. `auth:sanctum` only proves the bearer token is
| valid; it does not check WHICH model issued it, so on its own it would let a
| tenant user's token in here.
|
*/
Route::post('v1/staff/auth/login', [StaffAuthController::class, 'login']);

/*
 * Setting up a second factor with nobody else present (§4.2).
 *
 * `begin` is unauthenticated by necessity — a staff account with no confirmed
 * factor cannot sign in, which is the whole reason it needs this. It is reachable
 * ONLY while no confirmed secret exists, throttled per address, and every attempt
 * is audited. See EnrolmentController for what that trades and what bounds it.
 *
 * `confirm` needs the short-lived, single-ability token `begin` hands back — so
 * it sits behind auth:sanctum but deliberately NOT behind the `staff` middleware,
 * which would refuse an account that is not yet able to authenticate.
 */
Route::post('v1/staff/auth/enrolment', [EnrolmentController::class, 'begin']);
Route::post('v1/staff/auth/enrolment/confirm', [EnrolmentController::class, 'confirm'])
    ->middleware('auth:sanctum');

Route::prefix('v1/staff')
    ->middleware(['auth:sanctum', 'staff'])
    ->group(function () {
        Route::post('auth/logout', [StaffAuthController::class, 'logout']);
        Route::get('me', [StaffAuthController::class, 'me']);

        Route::get('organizations', [StaffController::class, 'organizations']);

        // The subsystems that fail silently — backups, mail, the outbox. See
        // HealthController for why a status code cannot answer this.
        Route::get('health', [HealthController::class, 'index']);
        Route::post('health/test-email', [HealthController::class, 'testEmail']);

        // Global module availability — the platform-wide gate that sits above
        // every organization's own permissions (§2.1, layer 3).
        Route::get('modules', [StaffController::class, 'modules']);
        Route::patch('modules/{key}', [StaffController::class, 'setModuleState']);

        Route::get('impersonations', [StaffController::class, 'impersonations']);
        Route::post('impersonations', [StaffController::class, 'impersonate']);
        Route::delete('impersonations/{impersonation}', [StaffController::class, 'endImpersonation']);

        // Staff accounts — Lead Developer only, enforced in the controller
        // rather than the route, so the refusal can say why.
        Route::get('accounts', [StaffAccountsController::class, 'index']);
        Route::post('accounts', [StaffAccountsController::class, 'store']);
        Route::patch('accounts/{staff}', [StaffAccountsController::class, 'update']);
        Route::post('accounts/{staff}/reset-link', [StaffAccountsController::class, 'resetLink']);
        Route::post('accounts/{staff}/link', [StaffAccountsController::class, 'link']);
        Route::delete('accounts/{staff}/link/{user}', [StaffAccountsController::class, 'unlink']);
        Route::post('accounts/{staff}/totp', [StaffAccountsController::class, 'resetTotp']);
        Route::post('accounts/{staff}/totp/confirm', [StaffAccountsController::class, 'confirmTotpFor']);

        // Your own authenticator — any tier, because moving to a new phone must
        // not require asking someone else for permission.
        Route::post('me/totp', [StaffAccountsController::class, 'beginOwnTotp']);
        Route::post('me/totp/confirm', [StaffAccountsController::class, 'confirmOwnTotp']);
    });
