<?php

use App\Http\Controllers\Api\AddressLookupController;
use App\Http\Controllers\Api\AttachmentsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationsController;
use App\Http\Controllers\Api\BlueskyConnectController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\BusinessProfileController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\DevelopmentUnlockController;
use App\Http\Controllers\Api\EnrolmentController;
use App\Http\Controllers\Api\FranchisesController;
use App\Http\Controllers\Api\GoogleMailAuthController;
use App\Http\Controllers\Api\GoogleWorkspaceAuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HrContractsController;
use App\Http\Controllers\Api\HrDashboardController;
use App\Http\Controllers\Api\HrInvitationsController;
use App\Http\Controllers\Api\HrOnboardingsController;
use App\Http\Controllers\Api\HrPeopleController;
use App\Http\Controllers\Api\InspectionDefinitionsController;
use App\Http\Controllers\Api\InspectionReportsController;
use App\Http\Controllers\Api\InspectionRunsController;
use App\Http\Controllers\Api\InventoryAdjustmentsController;
use App\Http\Controllers\Api\InventoryIssuesController;
use App\Http\Controllers\Api\InventoryItemsController;
use App\Http\Controllers\Api\InventoryLocationsController;
use App\Http\Controllers\Api\InventoryPurchaseOrdersController;
use App\Http\Controllers\Api\InventorySalesController;
use App\Http\Controllers\Api\InventoryStockController;
use App\Http\Controllers\Api\InventoryTransfersController;
use App\Http\Controllers\Api\InventoryVendorsController;
use App\Http\Controllers\Api\InvitationAcceptanceController;
use App\Http\Controllers\Api\JobsController;
use App\Http\Controllers\Api\LeadsController;
use App\Http\Controllers\Api\MailboxesController;
use App\Http\Controllers\Api\MailboxMessagesController;
use App\Http\Controllers\Api\MarketingController;
use App\Http\Controllers\Api\MetaCallbacksController;
use App\Http\Controllers\Api\MicrosoftMailAuthController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\OrganizationCanvasController;
use App\Http\Controllers\Api\OrganizationProfileController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\Phone\CallsController as PhoneCallsController;
use App\Http\Controllers\Api\Phone\CallTagsController as PhoneCallTagsController;
use App\Http\Controllers\Api\Phone\ConnectionsController as PhoneConnectionsController;
use App\Http\Controllers\Api\Phone\FlowsController as PhoneFlowsController;
use App\Http\Controllers\Api\Phone\HoursController as PhoneHoursController;
use App\Http\Controllers\Api\Phone\MessagesController as PhoneMessagesController;
use App\Http\Controllers\Api\Phone\NumbersController as PhoneNumbersController;
use App\Http\Controllers\Api\Phone\PortsController as PhonePortsController;
use App\Http\Controllers\Api\Phone\PortWebhookController as PhonePortWebhookController;
use App\Http\Controllers\Api\Phone\PresenceController as PhonePresenceController;
use App\Http\Controllers\Api\Phone\RecordingWebhookController as PhoneRecordingWebhookController;
use App\Http\Controllers\Api\Phone\RingGroupsController as PhoneRingGroupsController;
use App\Http\Controllers\Api\Phone\ServiceRequestsController as PhoneServiceRequestsController;
use App\Http\Controllers\Api\Phone\SmsWebhookController as PhoneSmsWebhookController;
use App\Http\Controllers\Api\Phone\StatusWebhookController as PhoneStatusWebhookController;
use App\Http\Controllers\Api\Phone\TokensController as PhoneTokensController;
use App\Http\Controllers\Api\Phone\TranscriptionWebhookController as PhoneTranscriptionWebhookController;
use App\Http\Controllers\Api\Phone\VoiceWebhookController as PhoneVoiceWebhookController;
use App\Http\Controllers\Api\PreferencesController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReviewsController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SendingIdentitiesController;
use App\Http\Controllers\Api\ServiceGroupsController;
use App\Http\Controllers\Api\ServiceLabelsController;
use App\Http\Controllers\Api\ServicesController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\SocialConnectionsController;
use App\Http\Controllers\Api\SocialProvidersController;
use App\Http\Controllers\Api\StaffAccountsController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffClientVersionsController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffPhoneRequestsController;
use App\Http\Controllers\Api\Tasks\MyTasksController;
use App\Http\Controllers\Api\Tasks\TaskBoardMembersController;
use App\Http\Controllers\Api\Tasks\TaskBoardPreferencesController;
use App\Http\Controllers\Api\Tasks\TaskBoardRecoveryController;
use App\Http\Controllers\Api\Tasks\TaskBoardRowsController;
use App\Http\Controllers\Api\Tasks\TaskBoardsController;
use App\Http\Controllers\Api\Tasks\TaskBulkController;
use App\Http\Controllers\Api\Tasks\TaskCollaboratorsController;
use App\Http\Controllers\Api\Tasks\TaskColumnsController;
use App\Http\Controllers\Api\Tasks\TaskColumnValuesController;
use App\Http\Controllers\Api\Tasks\TaskDetailsController;
use App\Http\Controllers\Api\Tasks\TaskDuplicationController;
use App\Http\Controllers\Api\Tasks\TaskExportsController;
use App\Http\Controllers\Api\Tasks\TaskFoldersController;
use App\Http\Controllers\Api\Tasks\TaskGroupsController;
use App\Http\Controllers\Api\Tasks\TaskImportsController;
use App\Http\Controllers\Api\Tasks\TaskOrderController;
use App\Http\Controllers\Api\Tasks\TaskParticipantsController;
use App\Http\Controllers\Api\Tasks\TaskPrioritiesController;
use App\Http\Controllers\Api\Tasks\TaskQuickSearchController;
use App\Http\Controllers\Api\Tasks\TaskRecordLinksController;
use App\Http\Controllers\Api\Tasks\TasksController;
use App\Http\Controllers\Api\Tasks\TaskStatusesController;
use App\Http\Controllers\Api\Tasks\TaskUpdateAttachmentsController;
use App\Http\Controllers\Api\Tasks\TaskUpdatesController;
use App\Http\Controllers\Api\Tasks\TaskViewsController;
use App\Http\Controllers\Api\Tasks\TaskWatchPreferencesController;
use App\Http\Controllers\Api\TimeClockController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UiStringsController;
use App\Http\Controllers\Api\VehicleAssignmentsController;
use App\Http\Controllers\Api\VehicleInsuranceController;
use App\Http\Controllers\Api\VehicleOwnershipController;
use App\Http\Controllers\Api\VehiclePaymentsController;
use App\Http\Controllers\Api\VehiclesController;
use App\Http\Controllers\Api\VehicleServiceController;
use App\Http\Middleware\EnsureTaskRouteAccess;
use App\Models\PlatformStaffLink;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\TwoFactorPolicy;
use App\Support\I18n\UiCatalogue;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Preferences\PreferenceRegistry;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\DisplaySettings;
use App\Support\Social\SocialProvider;
use App\Support\TimeClock\TimeClock;
use Illuminate\Http\Request;
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

    Route::post('login', [AuthController::class, 'login']);
    Route::post('choose', [AuthController::class, 'choose']);

    // Public by necessity — somebody who has forgotten their password cannot
    // sign in to ask. Both answer identically whatever the outcome, so neither
    // can be used to discover which addresses are registered.
    Route::post('forgot', [PasswordController::class, 'forgot']);
    Route::post('reset', [PasswordController::class, 'reset']);
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

/*
|--------------------------------------------------------------------------
| Finishing a Google sign-in — public, because a browser redirect has no token
|--------------------------------------------------------------------------
|
| Google ends the OAuth flow by redirecting a BROWSER to this URL. A redirect
| sends no Authorization header, so `auth:sanctum` here would refuse every
| legitimate callback there will ever be. There is nothing to fix with
| middleware, and adding it would only make the feature impossible.
|
| WHAT AUTHENTICATES IT INSTEAD is the `state` parameter, which names a row in
| `google_mail_sign_ins` binding the organization, the person and the mailbox
| together — issued by the authorized POST further down, expiring in ten
| minutes, and deleted the moment it is read. See App\Support\Email\
| GoogleOAuthSignIns for why the burn is a DELETE with an affected-row count
| rather than a flag, and GoogleMailAuthController for the order the callback
| does things in.
|
| THE PATH IS ALSO A REGISTERED VALUE AT GOOGLE. It is the redirect URI on the
| OAuth client, matched character for character, so it cannot be renamed here
| alone — App\Support\Email\GoogleOAuth::CALLBACK_PATH holds the same literal
| and GoogleMailOAuthTest asserts the two agree.
|
| `state` and `code` ARE IN THE QUERY STRING, unlike every other credential in
| this file — invitations moved theirs into a body precisely because nginx logs
| request lines. That is not a choice available here: OAuth 2.0 defines the
| callback as a redirect with query parameters, and Google will not send them
| any other way. What answers it is the design of the two values rather than
| their position. `state` is single-use and dead within ten minutes, so a copy
| in a log is a copy of something already spent. `code` is single-use at Google
| AND useless without the PKCE verifier, which never leaves this server and is
| encrypted where it rests.
|
| No `tenant` middleware either: the state row is what names an organization, so
| the organization cannot already be known when it is read. The controller
| enters it with TenantContext::run, the way InvitationAcceptanceController
| does.
|
| Throttled, because it is public and every hit does a database lookup. The
| numbers are inline rather than a named limiter — AppServiceProvider owns those
| and this needs nothing bespoke.
|
*/
Route::get('v1/email/google/callback', [GoogleMailAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('email.google.callback');

Route::get('v1/email/microsoft/callback', [MicrosoftMailAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('email.microsoft.callback');

/*
| The two callbacks Meta requires. Unauthenticated by necessity - Meta calls
| them, so there is no session and no token. The signed_request's HMAC is the
| authentication; see MetaCallbacksController.
|
| Throttled harder than the OAuth routes because these are public, take no
| credential, and delete things when they verify.
*/
Route::post('v1/social/meta/deauthorize', [MetaCallbacksController::class, 'deauthorize'])
    ->middleware('throttle:60,1')
    ->name('social.meta.deauthorize');

Route::post('v1/social/meta/data-deletion', [MetaCallbacksController::class, 'dataDeletion'])
    ->middleware('throttle:60,1')
    ->name('social.meta.data-deletion');

Route::get('v1/social/{network}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->where('network', SocialProvider::oauthRoutePattern())
    ->name('social.callback');

/*
|--------------------------------------------------------------------------
| Twilio Voice webhook — public, because Twilio posts with no Sanctum token
|--------------------------------------------------------------------------
|
| Twilio ends an inbound (or App-driven outbound) call by POSTing form fields
| to the Voice URL registered on the TwiML App. That POST carries no
| Authorization header, so `auth:sanctum` here would refuse every legitimate
| call there will ever be. There is nothing to fix with middleware, and
| adding it would only make the feature impossible.
|
| WHAT AUTHENTICATES IT INSTEAD is the X-Twilio-Signature header, validated
| with the Account Auth Token stored on THAT phone connection (`auth_token`,
| not the API key secret in `credentials`) — never the platform Twilio
| credentials. A global token would be a tenant-boundary break. See
| App\Support\Phone\TwilioSignature and VoiceWebhookController.
|
| THE PATH NAMES THE ORGANIZATION. `{organization}` and `{connection}` are
| plain UUID segments, not route-model-bound: binding would run a
| tenant-scoped find with no tenant, and the connection id is what tells us
| which organization to enter. The controller loads the Organization, then
| TenantContext::run, the way InvitationAcceptanceController and the Google
| OAuth callback do.
|
| No `tenant` middleware either: the path is what names an organization, so
| the organization cannot already be known when the request arrives.
|
| Not listed in EnforceMinimumClientVersion's always-permitted paths.
| Twilio sends no client version header; that gate fails open for requests
| without one, which is the intended behaviour for server-to-server posts.
|
| Throttled, because it is public and every hit does a database lookup. The
| numbers are inline rather than a named limiter — AppServiceProvider owns
| those and this needs nothing bespoke.
|
*/
Route::post('v1/phone/voice/{organization}/{connection}', PhoneVoiceWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('phone.voice.webhook');

Route::post('v1/phone/sms/{organization}/{connection}', PhoneSmsWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('phone.sms.webhook');

Route::post('v1/phone/status/{organization}/{connection}', PhoneStatusWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('phone.status.webhook');

Route::post('v1/phone/recording/{organization}/{connection}', PhoneRecordingWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('phone.recording.webhook');

Route::post('v1/phone/transcription/{organization}/{connection}', PhoneTranscriptionWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('phone.transcription.webhook');

Route::post('v1/phone/port/{organization}/{connection}', PhonePortWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('phone.port.webhook');

Route::get('v1/ui-strings', [UiStringsController::class, 'show'])
    ->middleware('throttle:60,1');

// ── Authenticated ────────────────────────────────────────────────
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant', 'subscription', 'two-factor', 'idle'])
    ->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware(EnsureTaskRouteAccess::class)->group(function () {
            Route::get('tasks/folders', [TaskFoldersController::class, 'index']);
            Route::post('tasks/folders', [TaskFoldersController::class, 'store']);
            Route::patch('tasks/folders/{folder}', [TaskFoldersController::class, 'update']);
            Route::post('tasks/folders/{folder}/archive', [TaskFoldersController::class, 'archive']);
            Route::post('tasks/folders/{folder}/restore', [TaskFoldersController::class, 'restore']);

            Route::get('tasks/boards', [TaskBoardsController::class, 'index']);
            Route::post('tasks/boards', [TaskBoardsController::class, 'store']);
            Route::get('tasks/my', [MyTasksController::class, 'index']);
            Route::get('tasks/search', [TaskQuickSearchController::class, 'index']);
            Route::get('tasks/boards/{board}', [TaskBoardsController::class, 'show']);
            Route::patch('tasks/boards/{board}', [TaskBoardsController::class, 'update']);
            Route::post('tasks/boards/{board}/archive', [TaskBoardsController::class, 'archive']);
            Route::post('tasks/boards/{board}/restore', [TaskBoardsController::class, 'restore']);
            Route::post('tasks/boards/{board}/duplicate', [TaskBoardsController::class, 'duplicate']);
            Route::get('tasks/boards/{board}/duplicate-source', [TaskBoardsController::class, 'duplicateSource']);
            Route::post('tasks/boards/{board}/imports/preview', [TaskImportsController::class, 'preview']);
            Route::post('tasks/boards/{board}/imports/commit', [TaskImportsController::class, 'commit']);
            Route::get('tasks/boards/{board}/exports/{format}', [TaskExportsController::class, 'show']);
            Route::get('tasks/boards/{board}/rows', [TaskBoardRowsController::class, 'index']);
            Route::get('tasks/boards/{board}/tasks/{taskId}', [TaskDetailsController::class, 'show']);
            Route::get('tasks/boards/{board}/views', [TaskViewsController::class, 'index']);
            Route::post('tasks/boards/{board}/views', [TaskViewsController::class, 'store']);
            Route::post('tasks/boards/{board}/views/reorder', [TaskViewsController::class, 'reorder']);
            Route::patch('tasks/boards/{board}/views/{viewId}', [TaskViewsController::class, 'update']);
            Route::delete('tasks/boards/{board}/views/{viewId}', [TaskViewsController::class, 'destroy']);
            Route::post('tasks/boards/{board}/views/{viewId}/duplicate', [TaskViewsController::class, 'duplicate']);
            Route::put('tasks/boards/{board}/views/{viewId}/preference', [TaskViewsController::class, 'preference']);

            Route::post('tasks/boards/{board}/tasks', [TasksController::class, 'store']);
            Route::patch('tasks/{task}', [TasksController::class, 'update']);
            Route::put('tasks/{task}/collaborators', [TaskCollaboratorsController::class, 'update']);
            Route::put('tasks/{task}/watch', [TaskWatchPreferencesController::class, 'update']);
            Route::post('tasks/{task}/update-attachments', [TaskUpdateAttachmentsController::class, 'store']);
            Route::post('tasks/{task}/updates', [TaskUpdatesController::class, 'store']);
            Route::patch('tasks/{task}/updates/{update}', [TaskUpdatesController::class, 'update']);
            Route::post('tasks/{task}/updates/{update}/archive', [TaskUpdatesController::class, 'archive']);
            Route::get('tasks/{task}/record-links/types', [TaskRecordLinksController::class, 'types']);
            Route::get('tasks/{task}/record-links/search', [TaskRecordLinksController::class, 'search']);
            Route::post('tasks/{task}/record-links', [TaskRecordLinksController::class, 'store']);
            Route::delete('tasks/{task}/record-links/{link}', [TaskRecordLinksController::class, 'destroy']);
            Route::post('tasks/{task}/archive', [TasksController::class, 'archive']);
            Route::post('tasks/{task}/restore', [TasksController::class, 'restore']);
            Route::put('tasks/{task}/columns/{column}', [TaskColumnValuesController::class, 'update']);
            Route::post('tasks/{task}/duplicate', [TaskDuplicationController::class, 'store']);
            Route::post('tasks/boards/{board}/bulk', [TaskBulkController::class, 'store']);
            Route::post('tasks/boards/{board}/order', [TaskOrderController::class, 'store']);

            Route::get('tasks/boards/{board}/members', [TaskBoardMembersController::class, 'index']);
            Route::post('tasks/boards/{board}/members', [TaskBoardMembersController::class, 'store']);
            Route::patch('tasks/boards/{board}/members/{membership}', [TaskBoardMembersController::class, 'update']);
            Route::delete('tasks/boards/{board}/members/{membership}', [TaskBoardMembersController::class, 'destroy']);
            Route::post('tasks/boards/{board}/recovery', [TaskBoardRecoveryController::class, 'store']);
            Route::put('tasks/boards/{board}/preference', [TaskBoardPreferencesController::class, 'update']);
            Route::get('tasks/boards/{board}/participants', [TaskParticipantsController::class, 'index']);

            Route::post('tasks/boards/{board}/groups', [TaskGroupsController::class, 'store']);
            Route::post('tasks/boards/{board}/groups/reorder', [TaskGroupsController::class, 'reorder']);
            Route::patch('tasks/boards/{board}/groups/{group}', [TaskGroupsController::class, 'update']);
            Route::post('tasks/boards/{board}/groups/{group}/archive', [TaskGroupsController::class, 'archive']);
            Route::post('tasks/boards/{board}/groups/{group}/restore', [TaskGroupsController::class, 'restore']);

            Route::post('tasks/boards/{board}/statuses', [TaskStatusesController::class, 'store']);
            Route::post('tasks/boards/{board}/statuses/reorder', [TaskStatusesController::class, 'reorder']);
            Route::patch('tasks/boards/{board}/statuses/{status}', [TaskStatusesController::class, 'update']);
            Route::post('tasks/boards/{board}/statuses/{status}/archive', [TaskStatusesController::class, 'archive']);
            Route::post('tasks/boards/{board}/statuses/{status}/restore', [TaskStatusesController::class, 'restore']);

            Route::post('tasks/boards/{board}/priorities', [TaskPrioritiesController::class, 'store']);
            Route::post('tasks/boards/{board}/priorities/reorder', [TaskPrioritiesController::class, 'reorder']);
            Route::patch('tasks/boards/{board}/priorities/{priority}', [TaskPrioritiesController::class, 'update']);
            Route::post('tasks/boards/{board}/priorities/{priority}/archive', [TaskPrioritiesController::class, 'archive']);
            Route::post('tasks/boards/{board}/priorities/{priority}/restore', [TaskPrioritiesController::class, 'restore']);

            Route::post('tasks/boards/{board}/columns', [TaskColumnsController::class, 'store']);
            Route::post('tasks/boards/{board}/columns/reorder', [TaskColumnsController::class, 'reorder']);
            Route::patch('tasks/boards/{board}/columns/core/{coreKey}', [TaskColumnsController::class, 'updateCore']);
            Route::post('tasks/boards/{board}/columns/{column}/options', [TaskColumnsController::class, 'storeOption']);
            Route::post('tasks/boards/{board}/columns/{column}/options/reorder', [TaskColumnsController::class, 'reorderOptions']);
            Route::patch('tasks/boards/{board}/columns/{column}/options/{option}', [TaskColumnsController::class, 'updateOption']);
            Route::post('tasks/boards/{board}/columns/{column}/options/{option}/archive', [TaskColumnsController::class, 'archiveOption']);
            Route::post('tasks/boards/{board}/columns/{column}/options/{option}/restore', [TaskColumnsController::class, 'restoreOption']);
            Route::patch('tasks/boards/{board}/columns/{column}', [TaskColumnsController::class, 'update']);
            Route::post('tasks/boards/{board}/columns/{column}/archive', [TaskColumnsController::class, 'archive']);
            Route::post('tasks/boards/{board}/columns/{column}/restore', [TaskColumnsController::class, 'restore']);
        });

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

        // drivers before {vehicle} so the literal path is not captured as an id
        Route::get('vehicles/drivers', [VehicleAssignmentsController::class, 'drivers']);
        Route::post('vehicles/{vehicle}/assignments', [VehicleAssignmentsController::class, 'store']);
        Route::post('vehicles/{vehicle}/unassign', [VehicleAssignmentsController::class, 'unassign']);
        Route::post('vehicles/{vehicle}/ownership', [VehicleOwnershipController::class, 'store']);
        Route::post('vehicles/{vehicle}/insurance', [VehicleInsuranceController::class, 'store']);
        Route::get('vehicles/{vehicle}/service', [VehicleServiceController::class, 'index']);
        Route::post('vehicles/{vehicle}/service', [VehicleServiceController::class, 'store']);
        Route::get('vehicles/{vehicle}/payments', [VehiclePaymentsController::class, 'index']);
        Route::post('vehicles/{vehicle}/payments', [VehiclePaymentsController::class, 'store']);
        // Leads — enquiries from connected advertising (EZ Leads).
        //
        // `sync` before `{lead}` so the literal path is not captured as an id,
        // the same reason `vehicles/drivers` sits above `vehicles/{vehicle}`.
        //
        // No `store`. Every lead arrives from a connected account; one somebody
        // typed in would be indistinguishable from one a member of the public
        // actually sent, and the record is only worth holding because it is not.
        // Reviews — what the public said on a connected Business Profile.
        //
        // Drafting and publishing are separate routes carrying separate
        // permissions: one writes text nobody outside can see, the other speaks
        // in public under the organization's name. No `store` — every review is
        // fetched from Google, and one somebody typed in would be a fake.
        Route::post('reviews/sync', [ReviewsController::class, 'sync']);
        Route::get('reviews', [ReviewsController::class, 'index']);
        Route::get('reviews/{review}', [ReviewsController::class, 'show']);
        Route::put('reviews/{review}/reply', [ReviewsController::class, 'draftReply']);
        Route::delete('reviews/{review}/reply', [ReviewsController::class, 'discardReply']);
        Route::post('reviews/{review}/reply/publish', [ReviewsController::class, 'publishReply']);
        // Business Profile — the listings the public sees on Maps.
        //
        // `sync` before `{location}` so the literal is not captured as an id.
        // No store, no destroy: a listing is created on Google, and deleting
        // one here would say nothing to Google while hiding a shop front from
        // whoever answers for it. Disconnecting the profile is what removes them.
        Route::post('business-profile/sync', [BusinessProfileController::class, 'sync']);
        Route::get('business-profile/locations', [BusinessProfileController::class, 'index']);
        Route::get('business-profile/locations/{location}', [BusinessProfileController::class, 'show']);
        Route::patch('business-profile/locations/{location}', [BusinessProfileController::class, 'update']);
        Route::post('leads/sync', [LeadsController::class, 'sync']);
        Route::get('leads', [LeadsController::class, 'index']);
        Route::get('leads/{lead}', [LeadsController::class, 'show']);
        Route::patch('leads/{lead}', [LeadsController::class, 'update']);
        Route::apiResource('vehicles', VehiclesController::class);
        Route::apiResource('inventory/items', InventoryItemsController::class);
        Route::apiResource('inventory/locations', InventoryLocationsController::class);
        Route::apiResource('inventory/vendors', InventoryVendorsController::class);
        Route::post('inventory/purchase-orders/{purchase_order}/open', [InventoryPurchaseOrdersController::class, 'open']);
        Route::post('inventory/purchase-orders/{purchase_order}/cancel', [InventoryPurchaseOrdersController::class, 'cancel']);
        Route::post('inventory/purchase-orders/{purchase_order}/receives', [InventoryPurchaseOrdersController::class, 'receive']);
        Route::apiResource('inventory/purchase-orders', InventoryPurchaseOrdersController::class)
            ->only(['index', 'store', 'show']);
        Route::get('inventory/stock', [InventoryStockController::class, 'index']);
        Route::post('inventory/adjustments', [InventoryAdjustmentsController::class, 'store']);
        Route::post('inventory/transfers', [InventoryTransfersController::class, 'store']);
        Route::apiResource('inventory/issues', InventoryIssuesController::class)
            ->only(['index', 'store']);
        Route::post('inventory/sales/{sale}/open', [InventorySalesController::class, 'open']);
        Route::post('inventory/sales/{sale}/cancel', [InventorySalesController::class, 'cancel']);
        Route::post('inventory/sales/{sale}/pay', [InventorySalesController::class, 'pay']);
        Route::post('inventory/sales/{sale}/fulfillments', [InventorySalesController::class, 'fulfill']);
        Route::apiResource('inventory/sales', InventorySalesController::class)
            ->only(['index', 'store', 'show']);
        Route::get('services/groups', [ServiceGroupsController::class, 'index']);
        Route::post('services/groups', [ServiceGroupsController::class, 'store']);
        Route::delete('services/groups/{id}', [ServiceGroupsController::class, 'destroy']);
        Route::get('services/labels', [ServiceLabelsController::class, 'index']);
        Route::post('services/labels', [ServiceLabelsController::class, 'store']);
        Route::delete('services/labels/{id}', [ServiceLabelsController::class, 'destroy']);
        Route::apiResource('services', ServicesController::class);

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

        /*
        | ── How the business arranged the PICTURE of that structure ──
        |
        | The Organization tab draws the three lines above as boxes on a canvas
        | somebody drags into an arrangement. This stores that arrangement — ONE
        | per organization, which everybody in the organization sees (§2.1
        | category 5), in the `settings` table under
        | `organization.structure_canvas`.
        |
        | HERE, beside the tree it draws, rather than beside `dashboard/layout`
        | further down. The two used to be the same KIND of write and are not
        | any more: that one is still one person's preference and this one is a
        | tenant setting with a permission in front of it. The grouping in this
        | file is by what a route serves anyway, and a reader who arrives here
        | asking "what can the Organization tab do?" has to find all of it in
        | one place.
        |
        | THERE IS NO MATCHING GET, and that is decided rather than missing. The
        | arrangement reaches the client on GET /companies, riding with the tree
        | it describes — that payload is refetched every time the Organization
        | screen opens, so a colleague's save is seen without a second endpoint.
        | (It used to ride on /me with the theme, which was right while it was a
        | preference and is wrong now: /me is answered once at launch and cached
        | in the client's session, so a shared arrangement would not have
        | appeared until the next sign-in.) A read endpoint here would still be
        | the same value in two places, and the first time they disagreed the
        | canvas would draw itself somewhere nobody chose.
        |
        | NO `can:` MIDDLEWARE — not because there is no gate, but because the
        | gate is two questions and `can:` can only ask the first. Saving this
        | needs `organizations.edit` AND an assignment at the whole
        | organization, since one drag changes what every colleague opens
        | tomorrow. Both are asked from
        | OrganizationCanvasController::refusalToArrange, which is also what
        | answers `may_arrange_reason` on the tree — so the sentence a dead
        | control shows and the sentence the 403 carries are the same string
        | rather than two spellings of one rule.
        |
        | `subscription` IS INHERITED, deliberately. §13 makes a lapsed tenant
        | read-only and none of the exemptions above cover this path, so a lapsed
        | tenant can look at their structure and cannot save a new arrangement of
        | it. That is the right side of the line — rearranging is not reading —
        | and the client shows the 402's own sentence rather than special-casing
        | it.
        */
        Route::put('organization/canvas', [OrganizationCanvasController::class, 'save']);
        Route::patch('organization', [OrganizationProfileController::class, 'update']);
        Route::get('address-lookup', AddressLookupController::class);

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

        // Punch clock — top-bar, not an HR tab. Empty bodies; the server clock
        // is the only time that counts (design §8).
        Route::post('time-clock/clock-in', [TimeClockController::class, 'clockIn']);
        Route::post('time-clock/clock-out', [TimeClockController::class, 'clockOut']);

        // Clock in–out chip on Human Resources. `hr.view` plus the org switch;
        // `?on=` is a calendar date in the current view timezone (design §9).
        Route::get('hr/time-clock', [TimeClockController::class, 'index']);

        // The tab the owner asked for. It computes nothing itself — the figures
        // are widgets in the shared registry, so headcount on this tab and
        // headcount on somebody's main dashboard are the same code.
        Route::get('hr/dashboard', [HrDashboardController::class, 'index']);

        Route::get('hr/people/scope-options', [HrPeopleController::class, 'scopeOptions']);
        Route::get('hr/people', [HrPeopleController::class, 'index']);
        // Before the {user} PATCH is irrelevant — different verbs — but declared
        // beside it so the three read as one resource. §9's consent answer, which
        // lives next to the person rather than in a privacy module of its own.
        Route::get('hr/people/{user}/consents', [HrPeopleController::class, 'consents']);
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
        /*
         * A cover image, staged before any version names it.
         *
         * Above `hr/contracts/{contract}` and NOT bound to one, both
         * deliberately. A contract being created has no id yet, so a cover that
         * could only be uploaded against an existing contract would be one you
         * could add on the second visit and not the first — see
         * App\Models\ContractCoverUpload. Publishing then names the id this
         * answers with, and the version pins its own copy of the bytes.
         */
        Route::post('hr/contracts/covers', [HrContractsController::class, 'storeCover']);
        Route::get('hr/contracts/covers/{upload}', [HrContractsController::class, 'showCover']);
        Route::get('hr/contracts', [HrContractsController::class, 'index']);
        Route::post('hr/contracts', [HrContractsController::class, 'store']);
        Route::get('hr/contracts/{contract}/versions', [HrContractsController::class, 'versions']);
        // Publishing is its own act with its own diff — see the controller for
        // why PATCH refuses a field list, or a body, rather than accepting one.
        Route::post('hr/contracts/{contract}/versions', [HrContractsController::class, 'publishVersion']);
        /*
         * The wording, rendered as the document it would produce.
         *
         * POST rather than GET because a body is the whole agreement — pages of
         * it — and a draft does not belong in a URL: it would be logged by every
         * proxy between here and the editor, and §9 says a contract's text is not
         * something to scatter. It writes nothing, and it is gated on `update`
         * rather than `view` because it renders a draft of what people sign.
         */
        Route::post('hr/contracts/{contract}/preview', [HrContractsController::class, 'preview']);
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
        // The row stays. Same shape as withdrawing an invitation: DELETE is
        // the act, cancelled is the status, and the record is how "we started
        // this and changed our mind" remains answerable.
        Route::delete('hr/onboardings/{onboarding}', [HrOnboardingsController::class, 'destroy']);
        Route::post('hr/onboardings/{onboarding}/sign', [HrOnboardingsController::class, 'sign']);
        Route::post('hr/onboardings/{onboarding}/amendments', [HrOnboardingsController::class, 'amend']);
        // The rendered document and its fingerprint — a preview before anything
        // is signed, the stored bytes afterwards. The FILE is downloaded through
        // `GET attachments/{id}` like every other file on the platform; this
        // answers with the content and the hash beside it, because the caller's
        // next act is to sign and it has to send back the fingerprint of exactly
        // what it received.
        Route::get('hr/onboardings/{onboarding}/document', [HrOnboardingsController::class, 'document']);
        /*
         * A PDF COPY of a stored revision — bytes, not JSON, and not a second
         * contract.
         *
         * Registered before the `{onboarding}` routes below would ever be reached
         * for it, and deliberately a sub-path of `document` rather than a format
         * suffix or an `?as=pdf` on the line above: it is a different act with a
         * different response, a different audit entry and a refusal of its own
         * before anything is signed. A query parameter that changed all three
         * would hide that.
         *
         * The FILE endpoint (`GET attachments/{id}`) cannot serve this, because
         * there is no attachment: the copy is converted from the stored document
         * on demand and stored nowhere. See HrOnboardingsController::documentPdf.
         */
        Route::get('hr/onboardings/{onboarding}/document/pdf',
            [HrOnboardingsController::class, 'documentPdf']);
        // Revealing one sensitive answer. Its own endpoint because ASKING is the
        // reveal: every other read of this record answers the mask, so each entry
        // in the log means somebody deliberately looked rather than that a screen
        // was opened.
        Route::get('hr/onboardings/{onboarding}/answers/{field}',
            [HrOnboardingsController::class, 'revealAnswer']);

        // ── Marketing ────────────────────────────────────────────
        //
        // One endpoint, for a sub-module that is declared and not built. It
        // serves the automation surface Newsletter has already registered — read
        // off the module registry, never restated here — so the tab can show
        // what a rule will be able to hang off and mark the ones nothing raises
        // yet. What the sub-module WILL be, and what it is waiting for, is
        // written on the screen; see client/lib/screens/marketing_screen.dart.
        //
        // Gated on `marketing.view` inside the controller rather than by a
        // policy, because there is no model to attach one to. Being new exempts
        // it from nothing: a role the organization has not granted the tab to is
        // refused here in a sentence, like everywhere else.
        Route::get('marketing/newsletter', [MarketingController::class, 'newsletter']);

        Route::get('social/providers', [SocialProvidersController::class, 'index']);
        Route::get('social/connections', [SocialConnectionsController::class, 'index']);
        Route::delete('social/connections/{connection}', [SocialConnectionsController::class, 'destroy']);
        Route::post('social/bluesky/connect', [BlueskyConnectController::class, 'store']);
        Route::post('social/{network}/start', [SocialAuthController::class, 'start'])
            ->where('network', SocialProvider::oauthRoutePattern());
        Route::get('social/{network}/status', [SocialAuthController::class, 'status'])
            ->where('network', SocialProvider::oauthRoutePattern());

        // ── Phone: Twilio connections (org / company) ────────────
        //
        // Authenticated. Public voice/SMS webhooks land in a later task outside
        // this Sanctum group. Connect is phone.edit + §4 reach; list is phone.view
        // narrowed to connections in reach.
        Route::get('phone/connections', [PhoneConnectionsController::class, 'index']);

        // Literal before any {connection} pattern, same reason as vehicles/drivers.
        // Phone accounts already under EzBizi's platform Twilio, so connecting a
        // company adopts the one that exists instead of minting a second with
        // the same name. Filtered to names this tenant owns.
        Route::get('phone/connections/subaccounts', [PhoneConnectionsController::class, 'subaccounts']);

        Route::post('phone/connections', [PhoneConnectionsController::class, 'store']);
        Route::delete('phone/connections/{connection}', [PhoneConnectionsController::class, 'destroy']);
        Route::get('phone/connections/{connection}/trust', [PhoneConnectionsController::class, 'trustShow']);
        Route::post('phone/connections/{connection}/trust', [PhoneConnectionsController::class, 'trustStore']);

        // Numbers hang on org / company / franchise. List is grant ∩ reach ∩
        // view-scope; buy/release need phone.edit and a resolved Twilio connection.
        // Literal paths before {number}, or "importable" is captured as an id.
        Route::get('phone/numbers/importable', [PhoneNumbersController::class, 'importable']);
        Route::post('phone/numbers/import', [PhoneNumbersController::class, 'import']);
        Route::get('phone/numbers', [PhoneNumbersController::class, 'index']);
        Route::post('phone/numbers', [PhoneNumbersController::class, 'store']);

        // How a line behaves — above all which group answers it. Without this
        // `ring_group_id` could never be set and every inbound call fell
        // through to voicemail.
        Route::patch('phone/numbers/{number}', [PhoneNumbersController::class, 'update']);
        Route::delete('phone/numbers/{number}', [PhoneNumbersController::class, 'destroy']);

        // §4 gate 2 — asking EzBizi for phone service, and what it answered.
        // Read by anyone who can open Phone; asking needs authority over the
        // organization, because it commits them to a paid extra.
        Route::get('phone/service-request', [PhoneServiceRequestsController::class, 'show']);
        Route::get('phone/service-request/companies', [PhoneServiceRequestsController::class, 'companies']);
        Route::post('phone/service-request', [PhoneServiceRequestsController::class, 'store']);

        // Who can be put in a ring group. Literal path, and NOT under
        // ring-groups/ so it cannot be captured as {group}.
        Route::get('phone/members', [PhoneRingGroupsController::class, 'members']);

        // Ring groups: who a line rings, and where unanswered calls go.
        Route::get('phone/ring-groups', [PhoneRingGroupsController::class, 'index']);
        Route::post('phone/ring-groups', [PhoneRingGroupsController::class, 'store']);
        Route::get('phone/ring-groups/{group}', [PhoneRingGroupsController::class, 'show']);
        Route::patch('phone/ring-groups/{group}', [PhoneRingGroupsController::class, 'update']);
        Route::delete('phone/ring-groups/{group}', [PhoneRingGroupsController::class, 'destroy']);

        // Port-in: start via gateway; status webhooks update phone_port_requests.
        Route::post('phone/ports', [PhonePortsController::class, 'store']);

        // Named open-hours schedules on a connection. Create may take a number
        // so franchise lines default timezone from franchises.timezone.
        // Call menus. One menu per flow — TwimlRenderer reads the first it
        // finds, so an editor that drew a tree would draw one that never runs.
        // What a call was about. Editing the list is phone.edit; putting one
        // ON a call is not — the people who answer the phone are usually
        // view-plus-a-line-grant, and they are who does the tagging.
        Route::get('phone/tags', [PhoneCallTagsController::class, 'index']);
        Route::post('phone/tags', [PhoneCallTagsController::class, 'store']);
        Route::patch('phone/tags/{tag}', [PhoneCallTagsController::class, 'update']);
        Route::delete('phone/tags/{tag}', [PhoneCallTagsController::class, 'destroy']);
        Route::patch('phone/calls/{call}/tag', [PhoneCallTagsController::class, 'tagCall']);

        Route::get('phone/flows', [PhoneFlowsController::class, 'index']);
        Route::post('phone/flows', [PhoneFlowsController::class, 'store']);
        Route::patch('phone/flows/{flow}', [PhoneFlowsController::class, 'update']);
        Route::delete('phone/flows/{flow}', [PhoneFlowsController::class, 'destroy']);

        Route::get('phone/hours', [PhoneHoursController::class, 'index']);
        Route::post('phone/hours', [PhoneHoursController::class, 'store']);
        Route::get('phone/hours/{schedule}', [PhoneHoursController::class, 'show']);
        Route::patch('phone/hours/{schedule}', [PhoneHoursController::class, 'update']);
        Route::delete('phone/hours/{schedule}', [PhoneHoursController::class, 'destroy']);

        // Voice SDK token + desk presence. phone.view and a granted number on
        // that connection; no grant → fixed 403 sentence.
        Route::post('phone/tokens', [PhoneTokensController::class, 'store']);
        Route::get('phone/presence', [PhonePresenceController::class, 'index']);
        Route::put('phone/presence', [PhonePresenceController::class, 'update']);

        // Records: calls on granted numbers. Playback streams Twilio media.
        Route::get('phone/calls', [PhoneCallsController::class, 'index']);
        Route::get('phone/calls/{call}/recording', [PhoneCallsController::class, 'recording']);

        // SMS from the Call desk. 10DLC / toll-free gate refuses until approved.
        Route::get('phone/messages', [PhoneMessagesController::class, 'index']);
        Route::post('phone/messages', [PhoneMessagesController::class, 'store']);

        // ── Inspection Builder ───────────────────────────────────
        //
        // Named inspections: a pointer row plus written-once versions.
        // `catalogue` sits before `{inspection_definition}` so it does not bind
        // as an id. Cover upload is on the definition (create already minted
        // one). DELETE is only a never-published draft; unpublish hides a
        // published one from the picker without touching versions or reports.
        Route::get('inspections/catalogue', [InspectionDefinitionsController::class, 'catalogue']);
        Route::get('inspections/search', [InspectionRunsController::class, 'search']);
        Route::get('inspections/startable', [InspectionRunsController::class, 'startable']);
        Route::get('inspections', [InspectionDefinitionsController::class, 'index']);
        Route::post('inspections', [InspectionDefinitionsController::class, 'store']);
        Route::get('inspections/reports', [InspectionReportsController::class, 'index']);
        Route::get('inspections/reports/{inspection_report}', [InspectionReportsController::class, 'show']);
        Route::patch('inspections/reports/{inspection_report}', [InspectionReportsController::class, 'update']);
        Route::post('inspections/reports/{inspection_report}/archive', [InspectionReportsController::class, 'archive']);
        Route::post('inspections/reports/{inspection_report}/unarchive', [InspectionReportsController::class, 'unarchive']);
        Route::post('inspections/runs', [InspectionRunsController::class, 'store']);
        Route::get('inspections/runs/{inspection_run}', [InspectionRunsController::class, 'show']);
        Route::patch('inspections/runs/{inspection_run}', [InspectionRunsController::class, 'update']);
        Route::post('inspections/runs/{inspection_run}/restart', [InspectionRunsController::class, 'restart']);
        Route::post('inspections/runs/{inspection_run}/submit', [InspectionRunsController::class, 'submit']);
        Route::delete('inspections/runs/{inspection_run}', [InspectionRunsController::class, 'destroy']);
        Route::post('inspections/{inspection_definition}/cover', [InspectionDefinitionsController::class, 'storeCover']);
        Route::get('inspections/{inspection_definition}/cover', [InspectionDefinitionsController::class, 'showCover']);
        Route::post('inspections/{inspection_definition}/publish', [InspectionDefinitionsController::class, 'publish']);
        Route::post('inspections/{inspection_definition}/unpublish', [InspectionDefinitionsController::class, 'unpublish']);
        Route::get('inspections/{inspection_definition}', [InspectionDefinitionsController::class, 'show']);
        Route::patch('inspections/{inspection_definition}', [InspectionDefinitionsController::class, 'update']);
        Route::delete('inspections/{inspection_definition}', [InspectionDefinitionsController::class, 'destroy']);

        // ── Email: shared team inboxes ───────────────────────────
        //
        // The owner's purpose, which decides the shape of every route below:
        // "the capacity to let people answer emails they don't regularly have
        // access to — like company wide emails that receive customer emails or
        // forms". An access-controlled window onto info@, not a CRM mail
        // integration — nothing here links a message to a customer or a job, and
        // nothing stores one.
        //
        // ── Two surfaces, two authorities ───────────────────────
        //
        // MailboxesController MANAGES connections: `email.create`, `email.edit`
        // and `email.delete`, plus §4 reach over the level an inbox hangs off.
        // MailboxMessagesController READS: `email.view`, plus reach, PLUS a
        // grant for that particular inbox. Connecting a mailbox and reading
        // somebody's correspondence are different sizes of authority, and only
        // the second is invisible.
        //
        // The per-inbox grant is a TENANT record and deliberately not a
        // permission row — App\Models\MailboxGrant sets out why putting one
        // tenant's inbox addresses into the platform-wide `permissions` table
        // would be a boundary break.
        //
        // There is NO grant route in this block, and that is deliberate. Grants
        // are written where roles are written: `PATCH /roles/{role}` carries an
        // `inboxes` list beside `permissions`, which is what the roles editor
        // actually saves. Two endpoints writing `mailbox_grants` under two
        // slightly different rules is how the rule ends up depending on which
        // one a client called — see the note where they used to be, in
        // MailboxesController.
        //
        // ── The message id is a PATH SEGMENT and is opaque ──────
        //
        // It is the provider's own id, never anything this application issued,
        // and it is used for exactly two things: asking the provider for that
        // message, and as the key of a `message_seen` row. It is not a database
        // id, so it cannot be enumerated to reach another tenant's anything —
        // the mailbox in the path is the tenant-scoped model, and it is the
        // authorization subject.
        //
        // Gated inside the controllers rather than by policies, because the
        // question is never about the Mailbox model alone: it is permission AND
        // grant AND reach, and App\Support\Email\MailboxAccess is the one place
        // all three are asked.
        Route::get('email/mailboxes', [MailboxesController::class, 'index']);
        Route::post('email/mailboxes', [MailboxesController::class, 'store']);

        Route::patch('email/mailboxes/{mailbox}', [MailboxesController::class, 'update']);
        Route::delete('email/mailboxes/{mailbox}', [MailboxesController::class, 'destroy']);
        Route::post('email/mailboxes/{mailbox}/connect', [MailboxesController::class, 'connect']);

        // ── Connecting a mailbox by signing in, rather than by password ──
        //
        // The other half of `connect` above, for a provider where the credential
        // cannot be typed. `start` only BEGINS the flow: it answers a URL at the
        // mail service for the client to open, and the credential arrives later
        // at the public callback registered further up this file.
        //
        // `status` exists because the client cannot see that callback happen.
        // The consent window stops belonging to it the moment it navigates, and
        // a browser will not let an opener read across origins — so the screen
        // polls the only party that saw the token arrive.
        //
        // NAMED `oauth/...` AND NOT `google/...`, deliberately. "Send somebody
        // to their mail service and wait" is the same shape for every provider
        // that will ever have a sign-in, and the screen that calls these never
        // spells "Google" — it prints the provider label the server publishes.
        // A mailbox whose provider has no sign-in flow is refused by `start` in
        // a sentence naming the address. The Google-specific part is the
        // CALLBACK, which has to be Google-specific: a redirect URI is
        // registered at one provider, character for character.
        //
        // Same authority as `connect` — `email.edit` plus §4 reach, asked of
        // App\Support\Email\MailboxAccess — because pressing a button and
        // typing a password attach the same kind of key to the same inbox. A
        // looser rule on the easier path is how the strict one becomes the one
        // nobody uses.
        //
        // `start` is a POST because it WRITES a pending sign-in row. A GET that
        // writes is a GET something eventually prefetches.
        Route::post('email/mailboxes/{mailbox}/oauth/start',
            [GoogleMailAuthController::class, 'start']);
        Route::get('email/mailboxes/{mailbox}/oauth/status',
            [GoogleMailAuthController::class, 'status']);

        // ── Connecting a Google Workspace domain, then picking inboxes ──
        //
        // A Workspace administrator proves they run the domain. Shared
        // addresses are then picked from the Directory. The callback is the
        // same public Google redirect as per-inbox sign-in — one registered
        // URI — and GoogleMailAuthController tries the Workspace sign-in row
        // first.
        Route::get('email/workspace', [GoogleWorkspaceAuthController::class, 'show']);
        Route::get('email/workspace/directory', [GoogleWorkspaceAuthController::class, 'directory']);
        Route::delete('email/workspace', [GoogleWorkspaceAuthController::class, 'destroy']);
        Route::post('email/workspace/oauth/start', [GoogleWorkspaceAuthController::class, 'start']);
        Route::get('email/workspace/oauth/status', [GoogleWorkspaceAuthController::class, 'status']);

        Route::get('email/mailboxes/{mailbox}/messages', [MailboxMessagesController::class, 'index']);
        Route::post('email/mailboxes/{mailbox}/messages/bulk', [MailboxMessagesController::class, 'bulk']);
        Route::get('email/mailboxes/{mailbox}/messages/{message}', [MailboxMessagesController::class, 'show']);
        Route::get('email/mailboxes/{mailbox}/messages/{message}/picture',
            [MailboxMessagesController::class, 'picture']);
        Route::get('email/mailboxes/{mailbox}/messages/{message}/file',
            [MailboxMessagesController::class, 'file']);
        Route::post('email/mailboxes/{mailbox}/messages/{message}/reply',
            [MailboxMessagesController::class, 'reply']);
        Route::post('email/mailboxes/{mailbox}/messages/{message}/seen',
            [MailboxMessagesController::class, 'markSeen']);
        Route::delete('email/mailboxes/{mailbox}/messages/{message}/seen',
            [MailboxMessagesController::class, 'unsee']);
        Route::delete('email/mailboxes/{mailbox}/messages/{message}',
            [MailboxMessagesController::class, 'destroy']);

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
        //
        // ── Where the client reaches these from ──────────────────
        //
        // The "Import & export" tab was removed on 2026-08-12 and the ROUTES did
        // not move. The lines below do not share a history, though, so naming
        // them one at a time is the only way to be right about any of them:
        //
        //   - `exports/{resource}` and `imports/{resource}` were reached from
        //     that one tab. They are now reached from the DataTransfer widget,
        //     which the Customers and Jobs tabs both put in their header.
        //   - `exports` — the catalogue — was read by that tab and is now read
        //     by both of those AND by Settings, which uses its `organization`
        //     block to decide whether to offer the two below.
        //   - `exports/bundle` and `exports/inventory` were on NO screen. That
        //     tab never listed them and nothing else did either: from the day
        //     each route shipped until 2026-08-12 the only way to call either
        //     was an API token and the URL. The organization's data section in
        //     Settings is their first interface, not their second home.
        //   - `customers/{customer}/export` still has no screen. It answers a
        //     §9 request for one customer's own file, and nothing in the client
        //     calls it — worth writing down so the next reader does not go
        //     hunting for the control.
        //
        // Worth stating because the obvious worry is misplaced. Removing the
        // `data` module took its only permission, `data.view`, with it — and
        // none of these routes ever consulted it.
        //
        // What each takes now, after export became its own action:
        //   exports/{resource}  the resource's view AND export permissions
        //   imports/{resource}  the resource's create permission
        //   exports/bundle      every resource's export permission — it returns
        //                       every row of every resource, so anything less
        //                       would be a second door beside the first
        //   exports/inventory   every resource's view permission. It emits
        //                       table names, columns, purposes and counts, and
        //                       never a row, so the export gate is not the one
        //                       that belongs on it
        //
        // Removing the tab is what MADE the export permission necessary: the
        // tab was the only way an organization could withhold bulk export, and
        // taking it away without replacing it would have handed every customer
        // list to anybody who could read one customer.
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
        Route::post('me/development/unlock', [DevelopmentUnlockController::class, 'store']);

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

        /*
        |── The address a tenant's own mail comes from (§6, §2.1) ──
        |
        | Beside settings because it IS one in every sense that matters — it
        | cascades franchise → company → organization, it is refused at a scope
        | the caller cannot reach, and it is gated on the same
        | `organizations.edit` the rest of the settings screen uses, so the
        | organization decides which of its roles reach it by granting that tab.
        | It is a table rather than a registry entry for reasons the migration
        | sets out at length, the first being that a registry value is settable
        | by PATCH and a verification state must never be.
        |
        | NO {identity} SEGMENT ANYWHERE. The scope names the row — there is at
        | most one per scope, enforced by a unique key — so there is no id for a
        | caller to guess, and no route model binding that could resolve one
        | belonging to a scope they cannot reach. `check` is a POST because it
        | writes: it records what DNS said and when it was asked.
        |
        | SINGULAR, and PATCH rather than PUT. A scope has one sending identity,
        | so the path names one thing. The verb is PATCH because the client's
        | ApiClient carries a query string on `patch` and `delete` and not on
        | `put` and `post`, and that file belongs to another piece of work — see
        | client/lib/widgets/sending_identity_panel.dart, which says the same.
        | It also lands these on the same two verbs `/settings` uses for the
        | same job at the same scopes, which is the better shape regardless.
        */
        Route::get('mail/sending-identity', [SendingIdentitiesController::class, 'index']);
        Route::patch('mail/sending-identity', [SendingIdentitiesController::class, 'update']);
        Route::delete('mail/sending-identity', [SendingIdentitiesController::class, 'destroy']);
        Route::post('mail/sending-identity/check', [SendingIdentitiesController::class, 'check']);

        // Who am I — what the client calls on launch to know what to render.
        // (staff routes are below, deliberately outside this group)
        Route::get('me', function (Request $request) {
            $user = $request->user();

            // Resolved once and used twice, below: the client is told where it
            // is standing AND what that place's settings say, and the two must
            // describe the same place or the dates on screen belong to a scope
            // the top bar is not naming.
            $view = ViewScope::forUser($user);

            // Asked twice below — for the keys and for the modules — and both
            // answers have to come from the same reading of the platform flags.
            $catalogue = app(PermissionCatalogue::class);

            return response()->json([
                'data' => [
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
                            'focused_at' => 'https://dev.ezbizi.com',
                        ];
                    })(),
                    // The organization requires a second factor and this account
                    // has none (§6). Carried here as well as on the login body
                    // because a page refresh restores the session from a stored
                    // token and never sees a login body — without it the app
                    // would come back with every screen quietly answering 403
                    // and nothing on screen saying why.
                    'two_factor_enrolment_required' => app(TwoFactorPolicy::class)->mustEnrol($user),

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
                            'id' => $c->id,
                            'name' => $c->name,
                            'parent_company_id' => $c->parent_company_id,
                            'has_logo' => $c->logo_path !== null,
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
                    'display' => app(DisplaySettings::class)
                        ->forScope($view->settingsScope()),

                    ...app(UiCatalogue::class)->forUser($user),

                    'franchises' => $user->reachableFranchises()->map(fn ($f) => [
                        'id' => $f->id,
                        'name' => $f->name,
                        'company_id' => $f->company_id,
                        'is_active' => $f->is_active,
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
Route::post('v1/staff/auth/login', [StaffAuthController::class, 'login'])
    ->middleware('staff-auth-host');

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
Route::post('v1/staff/auth/enrolment', [EnrolmentController::class, 'begin'])
    ->middleware('staff-auth-host');
Route::post('v1/staff/auth/enrolment/confirm', [EnrolmentController::class, 'confirm'])
    ->middleware(['staff-auth-host', 'auth:sanctum']);

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

        /*
         * The oldest client build each platform may still talk to.
         *
         * Sits beside `modules` because it is the same KIND of control: a
         * platform-wide switch owned by EzBizi staff, invisible to customers,
         * that changes what every organization experiences at the same moment.
         * The difference is that this one can stop a customer's software from
         * starting at all, which is why the write is audited and why the read
         * lists every platform rather than only the configured ones.
         *
         * PUT rather than PATCH: one save replaces a platform's whole
         * configuration, so clearing the download link is expressible. See
         * StaffClientVersionsController::update.
         *
         * BOTH ROUTES ARE EXEMPT FROM THE GATE THEY CONFIGURE — listed in
         * EnforceMinimumClientVersion::ALWAYS_PERMITTED. A minimum set too high
         * would otherwise refuse the request that lowers it again, and the only
         * remaining fix would be a shell on the production box.
         */
        // The phone service queue. Reads across every tenant on purpose —
        // control plane, behind the `staff` middleware, not `tenant`.
        Route::get('phone-requests', [StaffPhoneRequestsController::class, 'index']);
        Route::post('phone-requests/{request}/decide', [StaffPhoneRequestsController::class, 'decide']);

        Route::get('client-versions', [StaffClientVersionsController::class, 'index']);
        Route::put('client-versions/{platform}', [StaffClientVersionsController::class, 'update']);

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
