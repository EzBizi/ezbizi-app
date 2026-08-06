<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\EnrolmentController;
use App\Http\Controllers\Api\JobsController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PreferencesController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\WorkflowsController;
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
|
| Tenant must come after auth — there is no organization before there is a
| user. Subscription must come after tenant, since it reads the organization.
|
*/

// ── Public: no user and no organization exist yet ────────────────
Route::prefix('v1/auth')->group(function () {
    Route::post('login',  [AuthController::class, 'login']);
    Route::post('choose', [AuthController::class, 'choose']);

    // Public by necessity — somebody who has forgotten their password cannot
    // sign in to ask. Both answer identically whatever the outcome, so neither
    // can be used to discover which addresses are registered.
    Route::post('forgot', [PasswordController::class, 'forgot']);
    Route::post('reset',  [PasswordController::class, 'reset']);
});

// ── Authenticated ────────────────────────────────────────────────
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant', 'subscription'])
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

        // ── Import / export (§12, §9) ────────────────────────────
        //
        // Under /exports because EnforceSubscriptionState exempts that prefix by
        // pattern: §13 requires export to keep working after a lapse. Imports get
        // their own prefix precisely so they do NOT inherit that exemption.
        //
        // `bundle` is declared before `{resource}` or it binds as one and 404s.
        Route::get('exports', [DataController::class, 'catalogue']);
        Route::get('exports/bundle', [DataController::class, 'bundle']);
        Route::get('exports/{resource}', [DataController::class, 'export']);
        Route::get('customers/{customer}/export', [DataController::class, 'person']);

        Route::post('imports/{resource}', [DataController::class, 'import']);

        // ── Workflows & automations (§5.5) ───────────────────────
        //
        // The catalogue route sits BEFORE the resource, or /workflows/catalogue
        // binds as a workflow id and 404s.
        Route::get('workflows/catalogue', [WorkflowsController::class, 'catalogue']);
        Route::get('workflows/{workflow}/runs', [WorkflowsController::class, 'runs']);
        Route::post('workflows/{workflow}/test', [WorkflowsController::class, 'test']);
        Route::post('workflows/{workflow}/activate/{version}', [WorkflowsController::class, 'activateVersion']);
        Route::apiResource('workflows', WorkflowsController::class)->except(['store'])
            ->parameters(['workflows' => 'workflow']);
        Route::post('workflows', [WorkflowsController::class, 'store']);

        // Changing it while signed in. Needs the current password: a session
        // proves somebody sat down at an unlocked laptop, not that they are the
        // account holder.
        Route::post('me/password', [PasswordController::class, 'change']);

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

            return response()->json([
                'data' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => ['id' => $user->role?->id, 'name' => $user->role?->name],
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
                    'organization' => [
                        'id'              => $user->organization->id,
                        'name'            => $user->organization->name,
                        'franchise_label' => $user->organization->franchise_label,
                        'is_read_only'    => $user->organization->isReadOnly(),
                    ],
                    // Reach, so the client knows which franchises to offer —
                    // rather than showing all and failing on save (§4).
                    'franchises' => $user->reachableFranchises()->map(fn ($f) => [
                        'id'         => $f->id,
                        'name'       => $f->name,
                        'company_id' => $f->company_id,
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
