<?php

use App\Http\Controllers\Api\ClientIssuesController;
use App\Http\Controllers\Api\StaffClientIssuesController;
use App\Http\Controllers\Api\InstagramCommentsController;
use App\Http\Controllers\Api\FieldController;
use App\Http\Controllers\Api\FieldRecordingsController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AssistantEntriesController;
use App\Http\Controllers\Api\GuidelinesController;
use App\Http\Controllers\Api\AccountDeletionController;
use App\Http\Controllers\Api\AddressLookupController;
use App\Http\Controllers\Api\AdsPerformanceController;
use App\Http\Controllers\Api\AirtableConnectController;
use App\Http\Controllers\Api\AttachmentsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationsController;
use App\Http\Controllers\Api\BlueskyConnectController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\BusinessProfileController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\CustomerWorkizNotesController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\DevelopmentUnlockController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\EnrolmentController;
use App\Http\Controllers\Api\EstimatesController;
use App\Http\Controllers\Api\MembershipsController;
use App\Http\Controllers\Api\ExperimentalCallReportController;
use App\Http\Controllers\Api\FranchisesController;
use App\Http\Controllers\Api\GoogleMailAuthController;
use App\Http\Controllers\Api\GoogleWorkspaceAuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HrContractsController;
use App\Http\Controllers\Api\HrDashboardController;
use App\Http\Controllers\Api\HrInvitationsController;
use App\Http\Controllers\Api\HrOnboardingsController;
use App\Http\Controllers\Api\HrPeopleController;
use App\Http\Controllers\Api\HrPersonDetailsController;
use App\Http\Controllers\Api\InspectionCodesController;
use App\Http\Controllers\Api\InspectionDefinitionsController;
use App\Http\Controllers\Api\InspectionReportsController;
use App\Http\Controllers\Api\InspectionRunsController;
use App\Http\Controllers\Api\InventoryAdjustmentsController;
use App\Http\Controllers\Api\InventoryIssuesController;
use App\Http\Controllers\Api\InventoryItemsController;
use App\Http\Controllers\Api\InventoryLocationsController;
use App\Http\Controllers\Api\InventoryMovementsController;
use App\Http\Controllers\Api\InventoryPurchaseOrdersController;
use App\Http\Controllers\Api\InventorySalesController;
use App\Http\Controllers\Api\InventoryStockController;
use App\Http\Controllers\Api\InventorySummaryController;
use App\Http\Controllers\Api\InventoryTransfersController;
use App\Http\Controllers\Api\InventoryVendorsController;
use App\Http\Controllers\Api\InvitationAcceptanceController;
use App\Http\Controllers\Api\JobLinesController;
use App\Http\Controllers\Api\JobsController;
use App\Http\Controllers\Api\LeadDashboardController;
use App\Http\Controllers\Api\LeadPresenceController;
use App\Http\Controllers\Api\LeadReplyTemplatesController;
use App\Http\Controllers\Api\LeadsController;
use App\Http\Controllers\Api\MailboxesController;
use App\Http\Controllers\Api\MailboxMessagesController;
use App\Http\Controllers\Api\MailchimpConnectController;
use App\Http\Controllers\Api\MarketingController;
use App\Http\Controllers\Api\MetaCallbacksController;
use App\Http\Controllers\Api\MicrosoftMailAuthController;
use App\Http\Controllers\Api\Monitoring\CameraBridgeController;
use App\Http\Controllers\Api\Monitoring\CameraRelayController;
use App\Http\Controllers\Api\Monitoring\CameraSitesController;
use App\Http\Controllers\Api\Monitoring\CamerasController as MonitoringCamerasController;
use App\Http\Controllers\Api\Monitoring\DevicesController as MonitoringDevicesController;
use App\Http\Controllers\Api\Monitoring\FloorPlansController as MonitoringFloorPlansController;
use App\Http\Controllers\Api\Monitoring\OwnershipController as MonitoringOwnershipController;
use App\Http\Controllers\Api\Monitoring\HeartbeatController as MonitoringHeartbeatController;
use App\Http\Controllers\Api\Monitoring\LiveViewController as MonitoringLiveViewController;
use App\Http\Controllers\Api\Monitoring\FilesController as MonitoringFilesController;
use App\Http\Controllers\Api\Monitoring\RemoteControlController as MonitoringRemoteControlController;
use App\Http\Controllers\Api\Monitoring\ScreenshotsController as MonitoringScreenshotsController;
use App\Http\Controllers\Api\NewsletterAudiencesController;
use App\Http\Controllers\Api\NewslettersController;
use App\Http\Controllers\Api\NewsletterUnsubscribeController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\OrganizationCanvasController;
use App\Http\Controllers\Api\OrganizationProfileController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\Phone\AddressesController as PhoneAddressesController;
use App\Http\Controllers\Api\Phone\AnsweredWebhookController as PhoneAnsweredWebhookController;
use App\Http\Controllers\Api\Phone\BlockedNumbersController as PhoneBlockedNumbersController;
use App\Http\Controllers\Api\Phone\CallsController as PhoneCallsController;
use App\Http\Controllers\Api\Phone\CallTagsController as PhoneCallTagsController;
use App\Http\Controllers\Api\Phone\ConnectionsController as PhoneConnectionsController;
use App\Http\Controllers\Api\Phone\ExtensionsController as PhoneExtensionsController;
use App\Http\Controllers\Api\Phone\FlowsController as PhoneFlowsController;
use App\Http\Controllers\Api\Phone\HoursController as PhoneHoursController;
use App\Http\Controllers\Api\Phone\MessagesController as PhoneMessagesController;
use App\Http\Controllers\Api\Phone\NumbersController as PhoneNumbersController;
use App\Http\Controllers\Api\Phone\PortsController as PhonePortsController;
use App\Http\Controllers\Api\Phone\PortWebhookController as PhonePortWebhookController;
use App\Http\Controllers\Api\Phone\PresenceController as PhonePresenceController;
use App\Http\Controllers\Api\Phone\QueueCallbackWebhookController as PhoneQueueCallbackWebhookController;
use App\Http\Controllers\Api\Phone\QueueWaitWebhookController as PhoneQueueWaitWebhookController;
use App\Http\Controllers\Api\Phone\RecordingWebhookController as PhoneRecordingWebhookController;
use App\Http\Controllers\Api\Phone\ReportsController as PhoneReportsController;
use App\Http\Controllers\Api\Phone\RingGroupsController as PhoneRingGroupsController;
use App\Http\Controllers\Api\Phone\ServiceRequestsController as PhoneServiceRequestsController;
use App\Http\Controllers\Api\Phone\SmsWebhookController as PhoneSmsWebhookController;
use App\Http\Controllers\Api\Phone\StatusWebhookController as PhoneStatusWebhookController;
use App\Http\Controllers\Api\Phone\TokensController as PhoneTokensController;
use App\Http\Controllers\Api\Phone\TranscriptionWebhookController as PhoneTranscriptionWebhookController;
use App\Http\Controllers\Api\Phone\TrustController as PhoneTrustController;
use App\Http\Controllers\Api\Phone\VoicemailBoxesController as PhoneVoicemailBoxesController;
use App\Http\Controllers\Api\Phone\VoiceWebhookController as PhoneVoiceWebhookController;
use App\Http\Controllers\Api\Phone\WhisperWebhookController as PhoneWhisperWebhookController;
use App\Http\Controllers\Api\PreferencesController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\MarketingReportController;
use App\Http\Controllers\Api\MetaAdsReportController;
use App\Http\Controllers\Api\ScoringReportController;
use App\Http\Controllers\Api\StaffBotMenusController;
use App\Http\Controllers\Api\ThumbtackReportController;
use App\Http\Controllers\Api\ProfitLossController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ReviewsController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\SchedulingAreasController;
use App\Http\Controllers\Api\SchedulingAvailabilityController;
use App\Http\Controllers\Api\SchedulingBoardController;
use App\Http\Controllers\Api\SchedulingDispatchController;
use App\Http\Controllers\Api\SchedulingFranchiseLimitsController;
use App\Http\Controllers\Api\SchedulingLocationsController;
use App\Http\Controllers\Api\SchedulingOverbookingController;
use App\Http\Controllers\Api\SchedulingPatternController;
use App\Http\Controllers\Api\SchedulingSuggestController;
use App\Http\Controllers\Api\SchedulingTechniciansController;
use App\Http\Controllers\Api\SchedulingTimeSlotsController;
use App\Http\Controllers\Api\SendingServersController;
use App\Http\Controllers\Api\ServiceGroupsController;
use App\Http\Controllers\Api\ServiceLabelsController;
use App\Http\Controllers\Api\ServicesController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SmsCodesController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\SocialConnectionsController;
use App\Http\Controllers\Api\SocialMediaHandoffController;
use App\Http\Controllers\Api\QrCodesController;
use App\Http\Controllers\Api\SocialLibraryController;
use App\Http\Controllers\Api\SocialPostsController;
use App\Http\Controllers\Api\SocialProvidersController;
use App\Http\Controllers\Api\StaffAccountsController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffClientVersionsController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffMessagesController;
use App\Http\Controllers\Api\StaffPhoneRequestsController;
use App\Http\Controllers\Api\StaffSuggestionsController;
use App\Http\Controllers\Api\SuggestionsController;
use App\Http\Controllers\Api\ThumbtackWebhookController;
use App\Http\Controllers\Api\TimeClockController;
use App\Http\Controllers\Api\TimeClockCorrectionsController;
use App\Http\Controllers\Api\TimeClockReportsController;
use App\Http\Controllers\Api\Training\AssignmentsController as TrainingAssignmentsController;
use App\Http\Controllers\Api\Training\DashboardController as TrainingDashboardController;
use App\Http\Controllers\Api\Training\GuidesController as TrainingGuidesController;
use App\Http\Controllers\Api\Training\LearnController as TrainingLearnController;
use App\Http\Controllers\Api\Training\MediaController as TrainingMediaController;
use App\Http\Controllers\Api\Training\VideoController as TrainingVideoController;
use App\Http\Controllers\Api\Training\ProgressController as TrainingProgressController;
use App\Http\Controllers\Api\Training\TestsController as TrainingTestsController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UiStringsController;
use App\Http\Controllers\Api\VehicleAssignmentsController;
use App\Http\Controllers\Api\VehicleInsuranceController;
use App\Http\Controllers\Api\VehicleOwnershipController;
use App\Http\Controllers\Api\VehiclePaymentsController;
use App\Http\Controllers\Api\VehiclesController;
use App\Http\Controllers\Api\FinancialsController;
use App\Http\Controllers\Api\FinancialSourcesController;
use App\Http\Controllers\Api\FinancialBillsController;
use App\Http\Controllers\Api\FinancialAdjustmentsController;
use App\Http\Controllers\Api\VehicleServiceController;
use App\Http\Controllers\Api\Web\ContentController as WebContentController;
use App\Http\Controllers\Api\Web\EnquiriesController as WebEnquiriesController;
use App\Http\Controllers\Api\Web\HoursBatchController as WebHoursBatchController;
use App\Http\Controllers\Api\Web\SeoHelperController as WebSeoHelperController;
use App\Http\Controllers\Api\Web\PluginController as WebPluginController;
use App\Http\Controllers\Api\Web\PublicValuesController as WebPublicValuesController;
use App\Http\Controllers\Api\Web\SitesController as WebSitesController;
use App\Http\Controllers\Api\Web\SnippetController as WebSnippetController;
use App\Http\Controllers\Api\Web\ValuesController as WebValuesController;
use App\Http\Controllers\Api\Web\WidgetsController as WebWidgetsController;
use App\Http\Controllers\Api\Web\ListsController as WebListsController;
use App\Http\Controllers\Api\MetaMessagesWebhookController;
use App\Http\Controllers\Api\WhatsAppTemplatesController;
use App\Http\Controllers\Api\WhatsAppGroupsController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\WorkizWebhookController;
use App\Http\Controllers\Api\WorkizConnectController;
use App\Http\Controllers\Api\WorkizWebSessionController;
use App\Http\Controllers\Api\RingCentralConnectController;
use App\Models\PlatformStaffLink;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\TwoFactorPolicy;
use App\Support\I18n\UiCatalogue;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Monitoring\ScreenMonitoringExemption;
use App\Support\Preferences\PreferenceRegistry;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\DisplaySettings;
use App\Support\Social\SocialProvider;
use App\Support\TimeClock\TimeClock;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
// The apps reporting their own errors, crashes, freezes and failed calls
// (Jose, 2026-10-15). Outside auth on purpose: a crash on the sign-in screen
// still needs reporting. The controller reads the person from the token when
// there is one and rate-limits per person or address; this throttle is only
// the outer fence.
Route::post('v1/client-issues', [ClientIssuesController::class, 'store'])
    ->middleware('throttle:60,1');

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

    /*
     * Confirming a signup address (TODO #22). Public by necessity: the link is
     * opened from a mailbox, possibly on another device, by somebody with no
     * session.
     *
     * POST rather than GET, with the token in the BODY. nginx logs the request
     * line, which is how invitation credentials once ended up in an access log
     * kept for weeks. The email links the CLIENT at /verify?token=..., and the
     * client posts it here.
     *
     * `verify/resend` is throttled inside the controller, per address AND per
     * IP, the same shape `forgot` uses.
     */
    Route::post('verify', [EmailVerificationController::class, 'verify']);
    Route::post('verify/resend', [EmailVerificationController::class, 'resend']);
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

/*
| The media handoff. Instagram will not take an upload - it fetches an
| image_url or video_url with an anonymous crawler - so this serves one
| tenant's file to one fetch, then publishing deletes it. `signed` is the
| authentication and there is no other; MediaHandoff spells out what makes
| that safe enough to have chosen.
|
| Throttled tight. A real post causes ONE fetch, occasionally a retry.
*/
/*
| An image a training guide holds itself. `signed` is the authentication: the
| link is issued by TrainingMediaLinks only after the reader was authorised to
| read that guide. Throttled loosely enough that paging through a long guide,
| which loads an image per screen, is never refused.
*/
Route::get('v1/training/video/{platform}/{id}', [TrainingVideoController::class, 'show'])
    ->middleware('throttle:300,1')
    ->where(['platform' => 'vimeo|youtube', 'id' => '[A-Za-z0-9_-]{5,20}'])
    ->name('training.video');
Route::get('v1/training/media/{media}', [TrainingMediaController::class, 'show'])
    ->middleware(['signed', 'throttle:300,1'])
    ->whereUuid('media')
    ->name('training.media');

Route::get('v1/social/media/{handle}', [SocialMediaHandoffController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->where('handle', '[0-9A-Za-z]+(\.[0-9a-z]+)?')
    ->name('social.media');

/*
| Thumbtack telling us a lead arrived.
|
| Unauthenticated in the session sense - Thumbtack calls it, so there is no
| token and no user. A credential WE chose and gave them at registration comes
| back in our own header, and that header is the whole authentication; the body
| carries no proof of origin. See ThumbtackWebhookController.
|
| This is not a faster path to something we could poll for. Their API cannot
| list negotiations at all, so an outage here loses enquiries permanently.
| Throttled generously for that reason: refusing a real event costs a lead.
*/
/*
| WhatsApp - Meta telling us a message arrived, or what became of one we sent.
|
| Two routes, and the GET is a handshake rather than a read: Meta calls it once
| when somebody saves the callback URL and expects `hub.challenge` echoed back
| as PLAIN TEXT. A JSON envelope fails it and the URL cannot be saved.
|
| The POST is authenticated by an HMAC of the RAW body in X-Hub-Signature-256,
| so unlike Thumbtack's the body does carry proof of origin. It is still
| unauthenticated in the session sense - Meta calls it, there is no user.
|
| Throttled generously for the same reason as Thumbtack: refusing a real
| delivery loses a customer's message, and Meta disables a subscription that
| keeps failing.
*/
/*
 * Messenger and Instagram Direct, on one endpoint because Meta delivers both
 * here and tells them apart with `object`. Unauthenticated and signature-
 * checked, exactly like the WhatsApp pair below it.
 */
Route::get('v1/social/meta/messages/webhook', [MetaMessagesWebhookController::class, 'verify'])
    ->name('social.meta.messages.verify');

Route::post('v1/social/meta/messages/webhook', MetaMessagesWebhookController::class)
    ->name('social.meta.messages.webhook');

Route::get('v1/social/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:60,1')
    ->name('social.whatsapp.verify');

Route::post('v1/social/whatsapp/webhook', WhatsAppWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('social.whatsapp.webhook');

Route::post('v1/social/thumbtack/webhook', ThumbtackWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('social.thumbtack.webhook');

/*
| Workiz saying a job, lead, invoice or estimate changed: a "post webhook"
| rule in a branch's Workiz Automation Center. A nudge, not a delivery -- the
| record is read back from Workiz with the branch's own key. See
| WorkizWebhookController.
*/
Route::post('v1/social/workiz/webhook/{connection}', WorkizWebhookController::class)
    ->middleware('throttle:600,1')
    ->whereUuid('connection')
    ->name('social.workiz.webhook');

// The Workiz web session the "Send my Workiz session" extension posts. The
// signed, 30-minute link is the authority; see WorkizWebSessionController.
Route::post('v1/social/workiz-web/handoff/{connection}', [WorkizWebSessionController::class, 'handoff'])
    ->middleware(['signed', 'throttle:30,1'])
    ->whereUuid('connection')
    ->name('social.workiz-web.handoff');

/*
 * Unsubscribe — PUBLIC and unauthenticated, and it has to be.
 *
 * The person clicking is a customer of a customer. They have no EzBizi account
 * and never will, so any gate here would be a gate on the only lawful way out
 * of a mailing list.
 *
 * GET renders a page with a button and changes NOTHING: mail clients and
 * security scanners fetch every link in a message before a human sees it, and a
 * GET that unsubscribed people would empty a list silently. POST is what
 * records it. See the controller for what this does and does not defend against.
 */
Route::get('v1/newsletter/unsubscribe/{newsletter}', [NewsletterUnsubscribeController::class, 'show'])->middleware('throttle:30,1');
Route::post('v1/newsletter/unsubscribe/{newsletter}', [NewsletterUnsubscribeController::class, 'store'])
    ->middleware('throttle:30,1')
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::get('v1/social/{network}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->where('network', SocialProvider::oauthRoutePattern())
    ->name('social.callback');

/*
|--------------------------------------------------------------------------
| What a customer's WordPress plugin reads — public, and token-authenticated
|--------------------------------------------------------------------------
|
| No Sanctum: the caller is a plugin on somebody else's web host, with no user
| and no session. Its bearer token is a credential for ONE site, and the
| controller checks the host it reports against the one that token belongs to.
| No `tenant` middleware either, for the same reason as the invitation routes —
| the token row is what names the organization, so it cannot be known before
| the lookup. PluginController enters it with TenantContext::run.
|
| Throttled on the TOKEN rather than the IP, which is why this one is a named
| limiter and the OAuth callbacks above are not: these callers sit on shared web
| hosts, so one IP is a hosting company serving hundreds of unrelated
| businesses. AppServiceProvider carries the numbers and the reasoning.
*/
/*
 * A camera bridge PC (camera bridge, phase 1). No Sanctum and no `tenant`: the
 * caller is a PC with nobody signed in, and its site key names the organization,
 * so AuthenticateCameraBridge looks it up and enters it.
 */
Route::prefix('v1/monitoring/bridge')
    ->middleware(['throttle:120,1', App\Http\Middleware\AuthenticateCameraBridge::class])
    ->group(function () {
        Route::post('checkin', [CameraBridgeController::class, 'checkin']);
        Route::get('work', [CameraBridgeController::class, 'work']);
        Route::post('still', [CameraBridgeController::class, 'still']);
    });

// The box's MediaMTX asking about a token, or reporting viewers. X-Relay-Secret, no user.
Route::post('v1/camera-relay/auth', [CameraRelayController::class, 'auth'])->middleware('throttle:6000,1,camera-relay-auth');
Route::post('v1/camera-relay/viewers', [CameraRelayController::class, 'viewers'])->middleware('throttle:6000,1,camera-relay-viewers');
Route::get('v1/camera-relay/play-check', [CameraRelayController::class, 'playCheck'])->middleware('throttle:6000,1,camera-relay-play');

Route::get('v1/web/plugin/values', [WebPluginController::class, 'values'])
    ->middleware('throttle:web-plugin')
    ->name('web.plugin.values');

Route::post('v1/web/plugin/service-account', [WebPluginController::class, 'serviceAccount'])
    ->middleware('throttle:web-plugin')
    ->name('web.plugin.service-account');

// A site's own Elementor forms, passed on by the plugin (per-token limit, not per-IP).
Route::post('v1/web/plugin/form', [WebPluginController::class, 'form'])
    ->middleware('throttle:web-plugin')
    ->name('web.plugin.form');

/*
 * The chat widget and the contact form on a customer's own website.
 *
 * Public by necessity: a visitor's browser calls these, on a host we do not
 * run, with nobody signed in. The controller explains what holds the line.
 */
Route::post('v1/web/chat', [WebEnquiriesController::class, 'startChat'])
    ->middleware('throttle:web-enquiry')
    ->name('web.chat.start');

Route::post('v1/web/chat/{chat}/messages', [WebEnquiriesController::class, 'say'])
    ->middleware('throttle:web-enquiry')
    ->name('web.chat.say');

Route::get('v1/web/chat/{chat}/messages', [WebEnquiriesController::class, 'messages'])
    ->middleware('throttle:web-chat-poll')
    ->name('web.chat.messages');

// The live chat's people (Jose, 2026-10-01): who answers, who is typing, their photo.
Route::post('v1/web/chat/{chat}/typing', [WebEnquiriesController::class, 'typing'])
    ->middleware('throttle:web-chat-poll')
    ->name('web.chat.typing');

Route::get('v1/web/chat/avatar/{user}', [WebEnquiriesController::class, 'avatar'])
    ->middleware('signed')
    ->name('web.chat.avatar');

Route::post('v1/web/form', [WebEnquiriesController::class, 'submitForm'])
    ->middleware('throttle:web-enquiry')
    ->name('web.form.submit');

// The booking widget (Jose, 2026-10-08): open days for a ZIP, then a request a dispatcher approves.
Route::get('v1/web/booking/days', [WebEnquiriesController::class, 'bookingDays'])
    ->middleware('throttle:web-chat-poll')
    ->name('web.booking.days');

// A lead's own booking link (Jose, 2026-10-13): what it already knows, so the widget skips asking.
Route::get('v1/web/booking/lead', [WebEnquiriesController::class, 'bookingLead'])
    ->middleware('throttle:web-chat-poll')
    ->name('web.booking.lead');

Route::post('v1/web/booking', [WebEnquiriesController::class, 'submitBooking'])
    ->middleware('throttle:web-enquiry')
    ->name('web.booking.submit');

// Membership program, customer side (spec 2026-10-05). The review faces say who
// smiled by the site's public key; the join page's signed link is its permission.
Route::post('v1/web/review', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'review'])
    ->middleware('throttle:web-enquiry')
    ->name('web.review');
// What the customer did with their review link (Customer Experience, 2026-10-15).
Route::post('v1/web/review-link', [\App\Http\Controllers\Api\ReviewLinkEventsController::class, 'store'])
    ->middleware('throttle:web-enquiry');
// {{ membership_link }}: app.ezbizi.com/api/m/<code>, under /api because nginx
// sends only /api and /q/ to Laravel.
Route::get('m/{code}', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'shortJoin'])
    ->where('code', '[A-Za-z0-9]{7}')
    ->middleware('throttle:60,1')
    ->name('memberships.short');
// The review page's membership offer (2026-10-08), by the link's customer token.
Route::get('v1/web/membership', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'offer'])
    ->middleware('throttle:web-enquiry');
Route::post('v1/web/membership/join', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'offerJoin'])
    ->middleware('throttle:web-enquiry');
Route::get('v1/memberships/join/{customer}', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'joinPage'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('memberships.join');
Route::post('v1/memberships/join/{customer}', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'join'])
    ->middleware(['signed', 'throttle:20,1']);
Route::get('v1/memberships/visit/{membership}', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'visitPage'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('memberships.visit');
Route::post('v1/memberships/visit/{membership}', [\App\Http\Controllers\Api\MembershipsPublicController::class, 'visit'])
    ->middleware(['signed', 'throttle:20,1']);

Route::get('v1/web/form/challenge', [WebEnquiriesController::class, 'challenge'])
    ->middleware('throttle:web-enquiry')
    ->name('web.form.challenge');

// A phone recording (menu prompt, voicemail greeting), fetched by Twilio with
// no session — the signed link is the permission. See Phone\AudioController.
Route::get('v1/phone/audio/{name}', [\App\Http\Controllers\Api\Phone\AudioController::class, 'file'])
    ->middleware('signed')
    ->name('phone.audio');

// An inspection report's PDF for places that cannot sign in (a Workiz job);
// the signed link is the permission. See InspectionReport::shareUrl.
Route::get('v1/inspections/shared/{report}', [InspectionReportsController::class, 'shared'])
    ->middleware(['signed', 'throttle:60,1'])
    ->whereUuid('report')
    ->name('inspection.report.shared');
// A1 Tools' old customer report links, redirected here once that box is gone.
Route::get('v1/inspections/a1/{id}', [InspectionReportsController::class, 'a1Link'])
    ->middleware(['signed', 'throttle:60,1'])
    ->whereNumber('id')
    ->name('inspection.a1.link');

// A form upload, by the signed link in its enquiry — never guessable.
Route::get('v1/web/form/files/{name}', [WebEnquiriesController::class, 'file'])
    ->middleware('signed')
    ->name('web.form.file');

/*
|--------------------------------------------------------------------------
| What a BROWSER may read — for sites with nowhere to keep a secret
|--------------------------------------------------------------------------
|
| The route above is for a plugin on a server, holding a secret token. This one
| is for a `<script>` on a static page, where nothing is secret: it takes the
| site's PUBLIC key instead, and returns only values and the field list — never
| the host, label or company name the authenticated route includes.
|
| Throttled per IP, and that is the opposite choice from the route above for the
| opposite reason. A plugin is one server making one request for a whole site,
| so its IP is a shared host and the token is the only fair key. This one is
| called by every VISITOR's browser, so the IP is one person and per-IP is
| exactly right — a key-based limit here would let one popular site exhaust its
| own visitors' allowance.
|
| Takes no custom header, deliberately: a request with an Authorization header
| would trip a CORS preflight and double the traffic for no benefit.
*/
Route::get('v1/web/public/values', [WebPublicValuesController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('web.public.values');

/*
| The browser snippet itself. Under /api because nginx roots this host at the
| Flutter build and never serves Laravel's public/ — see SnippetController for
| why that is not worked around with an nginx location.
*/
Route::get('v1/web/ezbizi.js', [WebSnippetController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('web.snippet');

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

/*
| What the person we CALLED hears before the legs are joined.
|
| Twilio fetches this from <Number url="..."> when the callee answers, and
| plays it to THEM. It exists because the recording notice is a top-level
| <Say>, which on an outbound browser call runs on the employee's leg — so
| without this the party being recorded is the one party who is never told.
|
| GET as well as POST: Twilio's <Number url> is fetched with the method the
| verb attribute names, and it defaults to POST, but a callback that only
| answers one verb is one nobody can test with a browser.
*/
Route::match(['get', 'post'], 'v1/phone/whisper/{organization}/{connection}/{number}', PhoneWhisperWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('phone.whisper.webhook');

/*
| Requested on the leg that ANSWERS a group dial, and on no other — which is
| the only way Twilio will tell us which of the people it rang picked up.
| Throttled higher than the whisper: a busy line rings many people, but only
| one request lands per answered call.
*/
Route::match(['get', 'post'], 'v1/phone/answered/{organization}/{connection}/{user}', PhoneAnsweredWebhookController::class)
    ->middleware('throttle:240,1')
    ->name('phone.answered.webhook');

/*
| What a caller on hold hears, and where the queue decides to let them go.
|
| Twilio re-requests this for as long as somebody is in the queue — every time
| the document it returns runs out of verbs — so it is requested far more often
| than any other phone callback and the limit is raised to match: a single
| caller holding for five minutes with a thirty-second music loop is ten
| requests on their own.
|
| GET as well as POST for the same reason as the whisper: a callback that only
| answers one verb is one nobody can look at in a browser.
*/
Route::match(['get', 'post'], 'v1/phone/queue/{organization}/{connection}/{number}/{group}', PhoneQueueWaitWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('phone.queue.webhook');

/*
| Where the digit lands when somebody presses 1 rather than keep holding.
|
| Same four segments and the same throttle as the wait URL above, because it
| is the same caller in the same queue — and the controller reconstructs the
| wait URL from this one by swapping the segment, which only works while the
| two stay the same shape.
*/
Route::match(['get', 'post'], 'v1/phone/callback/{organization}/{connection}/{number}/{group}', PhoneQueueCallbackWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('phone.queue.callback.webhook');

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
    ->middleware(['auth:sanctum', 'tenant', 'subscription', 'verified', 'two-factor', 'idle', 'api-key'])
    ->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);

        /*
         * Suggestions — the one module with no permission behind it.
         *
         * Deliberately absent from ModuleRegistry so that no organization can
         * remove it (see App\Models\Suggestion), which means there is no key for
         * EnsureModuleAvailable or a permission middleware to check. Reachable
         * by anybody signed in, which is the requirement.
         */
        Route::get('suggestions', [SuggestionsController::class, 'index']);
        Route::post('suggestions', [SuggestionsController::class, 'store']);
        Route::patch('suggestions/{suggestion}', [SuggestionsController::class, 'update']);
        Route::delete('suggestions/{suggestion}', [SuggestionsController::class, 'destroy']);
        Route::get('suggestions/{suggestion}/files/{index}', [SuggestionsController::class, 'file'])->whereNumber('index');

        // ── The vertical slice: Customer → Job (§18 Phase 2) ─────
        //
        // Customers are shared org-wide and jobs are not, so the two look
        // deliberately different: the customer list is unfiltered, the job list
        // is scoped to the caller's reach. Both are enforced in policies, not
        // here — a route file is a bad place to keep an authorization rule,
        // because the next endpoint added beside it will not inherit the thought.
        // BEFORE the resource, or `customers/{customer}` claims it: "duplicates"
        // is a perfectly good uuid-shaped-nothing to a route that accepts any
        // id, and the failure is a 404 about a customer rather than a route
        // that was never reached.
        Route::get('customers/duplicates', [CustomersController::class, 'duplicates']);
        Route::apiResource('customers', CustomersController::class);
        Route::post('customers/{customer}/owner', [CustomersController::class, 'reassignOwner']);
        // Each branch's Workiz notes on the customer, read live, never copied.
        Route::get('customers/{customer}/workiz-notes', CustomerWorkizNotesController::class);

        Route::apiResource('jobs', JobsController::class);

        // Task Board (UI queue 80). Visibility and writes are decided per board
        // by TaskBoardPolicy: your own board always, team boards by tasks.* and view.
        Route::get('task-boards', [\App\Http\Controllers\Api\TaskBoardsController::class, 'index']);
        Route::get('task-boards/{taskBoard}', [\App\Http\Controllers\Api\TaskBoardsController::class, 'show']);
        // Custom boards and members, monday-style (inbox 0120).
        Route::post('task-boards', [\App\Http\Controllers\Api\TaskBoardsController::class, 'store']);
        Route::patch('task-boards/{taskBoard}', [\App\Http\Controllers\Api\TaskBoardsController::class, 'update']);
        Route::delete('task-boards/{taskBoard}', [\App\Http\Controllers\Api\TaskBoardsController::class, 'destroy']);
        Route::get('task-boards/{taskBoard}/members', [\App\Http\Controllers\Api\TaskBoardsController::class, 'members']);
        Route::post('task-boards/{taskBoard}/members', [\App\Http\Controllers\Api\TaskBoardsController::class, 'storeMember']);
        Route::patch('task-boards/{taskBoard}/members/{member}', [\App\Http\Controllers\Api\TaskBoardsController::class, 'updateMember']);
        Route::delete('task-boards/{taskBoard}/members/{member}', [\App\Http\Controllers\Api\TaskBoardsController::class, 'destroyMember']);
        Route::post('task-boards/{taskBoard}/statuses', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'storeStatus']);
        Route::patch('task-boards/{taskBoard}/statuses/{status}', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'updateStatus']);
        Route::delete('task-boards/{taskBoard}/statuses/{status}', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'destroyStatus']);
        Route::post('task-boards/{taskBoard}/columns', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'storeColumn']);
        Route::patch('task-boards/{taskBoard}/columns/{column}', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'updateColumn']);
        Route::delete('task-boards/{taskBoard}/columns/{column}', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'destroyColumn']);
        Route::post('task-boards/{taskBoard}/groups', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'storeGroup']);
        Route::patch('task-boards/{taskBoard}/groups/{group}', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'updateGroup']);
        Route::delete('task-boards/{taskBoard}/groups/{group}', [\App\Http\Controllers\Api\TaskBoardSettingsController::class, 'destroyGroup']);
        Route::post('tasks', [\App\Http\Controllers\Api\TasksController::class, 'store']);
        Route::get('tasks/{task}', [\App\Http\Controllers\Api\TasksController::class, 'show']);
        Route::patch('tasks/{task}', [\App\Http\Controllers\Api\TasksController::class, 'update']);
        Route::delete('tasks/{task}', [\App\Http\Controllers\Api\TasksController::class, 'destroy']);
        Route::post('tasks/{task}/updates', [\App\Http\Controllers\Api\TasksController::class, 'storeUpdate']);

        // Lines hang off a job and are never addressed without one — a line id
        // alone would be an id oracle across franchises.
        Route::post('jobs/{job}/lines', [JobLinesController::class, 'store']);
        Route::post('jobs/{job}/notes', [\App\Http\Controllers\Api\JobNotesController::class, 'store']);
        Route::patch('jobs/{job}/lines/{line}', [JobLinesController::class, 'update']);
        Route::delete('jobs/{job}/lines/{line}', [JobLinesController::class, 'destroy']);

        // Estimates live inside Jobs (spec 2026-09-29 §4); EstimatePolicy asks for
        // Jobs' Financials toggle on every one of these.
        Route::apiResource('estimates', EstimatesController::class);

        // Customers › Memberships (spec 2026-10-05). Plans before {membership}.
        Route::get('memberships/plans', [MembershipsController::class, 'plans']);
        Route::post('memberships/plans', [MembershipsController::class, 'storePlan']);
        Route::patch('memberships/plans/{plan}', [MembershipsController::class, 'updatePlan']);
        Route::apiResource('memberships', MembershipsController::class)->except('show');

        // drivers before {vehicle} so the literal path is not captured as an id
        Route::get('vehicles/drivers', [VehicleAssignmentsController::class, 'drivers']);
        // Fleet's General tab. Before `vehicles/{vehicle}`, same reason as drivers.
        Route::get('vehicles/summary', [\App\Http\Controllers\Api\FleetSummaryController::class, 'index'])
            ->middleware('permission:fleet_management.general.view');
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
        // Google Ads spend and performance. Account and Campaign only - the
        // two levels Google's Reporting Minimum Functionality is answered for.
        // Adding an ad group, ad or keyword level here is not a route change,
        // it is five more mandatory fields per level and a fresh submission.
        // The connected accounts, from our own rows. Instant, and asks
        // Google nothing - the screen lists these and loads ONE on demand.
        Route::get('ads/accounts', [AdsPerformanceController::class, 'accounts']);
        Route::get('ads/performance', [AdsPerformanceController::class, 'index']);
        Route::post('ads/campaigns/status', [AdsPerformanceController::class, 'setStatus']);
        // A manager account: what everything beneath it spent. The one place a
        // fan-out is still the answer, and it costs nothing until asked for.
        Route::get('ads/rollup', [AdsPerformanceController::class, 'rollup']);

        Route::post('reviews/sync', [ReviewsController::class, 'sync']);
        Route::get('reviews', [ReviewsController::class, 'index']);
        // What customers did with their review links (Customer Experience, 2026-10-15).
        Route::get('customer-experience/review-links', \App\Http\Controllers\Api\ReviewLinkReportController::class);
        Route::get('reviews/{review}', [ReviewsController::class, 'show']);
        // Working a review as a case (UI queue 101, 2026-10-16): reviews.manage.
        Route::patch('reviews/{review}', [ReviewsController::class, 'update']);
        Route::post('reviews/{review}/notes', [ReviewsController::class, 'addNote']);
        Route::get('reviews/{review}/customer-suggestions', [ReviewsController::class, 'customerSuggestions']);
        Route::put('reviews/{review}/customer', [ReviewsController::class, 'setCustomer']);
        Route::post('reviews/{review}/report', [ReviewsController::class, 'report']);
        Route::put('reviews/{review}/reply', [ReviewsController::class, 'draftReply']);
        Route::delete('reviews/{review}/reply', [ReviewsController::class, 'discardReply']);
        Route::post('reviews/{review}/reply/suggest', [ReviewsController::class, 'suggestReply']);
        Route::post('reviews/{review}/reply/publish', [ReviewsController::class, 'publishReply']);
        // Business Profile — the listings the public sees on Maps.
        //
        // `sync` before `{location}` so the literal is not captured as an id.
        // No store, no destroy: a listing is created on Google, and deleting
        // one here would say nothing to Google while hiding a shop front from
        // whoever answers for it. Disconnecting the profile is what removes them.
        Route::post('business-profile/sync', [BusinessProfileController::class, 'sync']);
        /*
         * A1Leads GBP - the read-only window onto Rodrigo's system.
         *
         * One route, one permission, and nothing else. It is NOT part of the
         * Business Profile module below it: that one reads EzBizi's own tables
         * and is what a Google reviewer is shown when our own API access
         * arrives. This one is a bridge and is deleted with its module.
         *
         * Gated by middleware rather than in the controller because the route
         * serves exactly one tab, which is the condition inbox 0051 sets for
         * the middleware being the right place.
         */
        // Sales pipeline: quoted jobs awaiting approval (Jose, 2026-10-14).
        Route::get('sales/deals', [SalesController::class, 'index'])->middleware('permission:sales.view');
        Route::put('sales/deals/{job}', [SalesController::class, 'update'])->middleware('permission:sales.manage');

        Route::get('business-profile/locations', [BusinessProfileController::class, 'index']);
        // Customer Experience › Complaints (Jose, 2026-10-20). Each checks its own grant.
        Route::get('complaints', [\App\Http\Controllers\Api\ComplaintsController::class, 'index']);
        Route::get('complaints/{complaint}', [\App\Http\Controllers\Api\ComplaintsController::class, 'show']);
        Route::patch('complaints/{complaint}', [\App\Http\Controllers\Api\ComplaintsController::class, 'update']);
        Route::post('complaints/{complaint}/notes', [\App\Http\Controllers\Api\ComplaintsController::class, 'addNote']);
        // How listings perform on Google (A1 Leads takeover, 2026-10-20).
        Route::get('business-profile/performance', [\App\Http\Controllers\Api\BusinessProfilePerformanceController::class, 'index']);
        Route::get('business-profile/locations/{location}/search-terms', [\App\Http\Controllers\Api\BusinessProfilePerformanceController::class, 'searchTerms']);
        // Lets a rule publish review replies on this listing (A1 Leads takeover, 2026-10-20). Off by default.
        Route::put('business-profile/locations/{location}/auto-reply', \App\Http\Controllers\Api\BusinessProfileAutoReplyController::class);
        Route::get('business-profile/locations/{location}', [BusinessProfileController::class, 'show']);
        Route::patch('business-profile/locations/{location}', [BusinessProfileController::class, 'update']);
        Route::post('leads/sync', [LeadsController::class, 'sync']);
        Route::post('leads/read-all', [LeadsController::class, 'readAll']);
        Route::get('leads/dashboard', [LeadDashboardController::class, 'show']);
        Route::get('leads/stats', [\App\Http\Controllers\Api\LeadStatsController::class, 'show']);
        Route::get('leads/stats/points', [\App\Http\Controllers\Api\LeadStatsController::class, 'points']);
        Route::get('leads/reply-templates', [LeadReplyTemplatesController::class, 'index']);
        Route::put('leads/reply-templates/{company}', [LeadReplyTemplatesController::class, 'update']);
        Route::get('leads', [LeadsController::class, 'index']);
        Route::get('leads/{lead}', [LeadsController::class, 'show']);
        Route::get('leads/{lead}/conversations/{conversation}/recording', [LeadsController::class, 'recording']);
        Route::post('leads/{lead}/messages', [LeadsController::class, 'reply']);
        Route::post('leads/{lead}/suggest-reply', [LeadsController::class, 'suggestReply']);
        Route::post('leads/{lead}/read', [LeadsController::class, 'read']);
        Route::post('leads/{lead}/follow-up-handled', [LeadsController::class, 'followUpHandled']);
        Route::post('leads/{lead}/reclassify', [LeadsController::class, 'reclassify']);
        Route::get('leads/{lead}/timeline', [LeadsController::class, 'timeline']);
        Route::patch('leads/{lead}/conversations/{conversation}/caller-type', [LeadsController::class, 'callerType']);
        Route::post('leads/{lead}/leave', [LeadPresenceController::class, 'leave']);
        Route::post('leads/{lead}/typing', [LeadsController::class, 'typing']);
        Route::post('leads/{lead}/book', [LeadsController::class, 'book']);
        Route::post('leads/{lead}/google-credit', [LeadsController::class, 'requestCredit']);
        Route::post('leads/{lead}/booking/{decision}', [LeadsController::class, 'decideBooking'])->whereIn('decision', ['approve', 'decline']);
        Route::patch('leads/{lead}', [LeadsController::class, 'update']);
        Route::apiResource('vehicles', VehiclesController::class);
        // Financials — the Airtable replacement. Actions live in FinancialBasePolicy.
        Route::middleware('permission:financials.settings.view')->group(function (): void {
            Route::get('financials/sources', [FinancialSourcesController::class, 'index']);
            Route::post('financials/sources', [FinancialSourcesController::class, 'store']);
            Route::patch('financials/sources/{source}', [FinancialSourcesController::class, 'update']);
            Route::delete('financials/sources/{source}', [FinancialSourcesController::class, 'destroy']);
        });
        Route::middleware('permission:financials.bases.view')->group(function (): void {
            Route::get('financials/adjustments', [FinancialAdjustmentsController::class, 'index']);
            Route::post('financials/adjustments', [FinancialAdjustmentsController::class, 'store']);
            Route::patch('financials/adjustments/{adjustment}', [FinancialAdjustmentsController::class, 'update']);
            Route::delete('financials/adjustments/{adjustment}', [FinancialAdjustmentsController::class, 'destroy']);
        });
        Route::middleware('permission:financials.bills.view')->group(function (): void {
            Route::get('financials/bills', [FinancialBillsController::class, 'index']);
            Route::post('financials/bills/read', [FinancialBillsController::class, 'read']);
            Route::post('financials/bills', [FinancialBillsController::class, 'store']);
            Route::get('financials/bills/{bill}/image', [FinancialBillsController::class, 'image']);
            Route::delete('financials/bills/{bill}', [FinancialBillsController::class, 'destroy']);
        });
        Route::prefix('financials')->middleware('permission:financials.bases.view')->controller(FinancialsController::class)->group(function (): void {
            Route::get('home', 'home');
            Route::get('bases', 'bases');
            Route::post('bases', 'storeBase');
            Route::patch('bases/{base}', 'updateBase');
            Route::delete('bases/{base}', 'destroyBase');
            Route::post('bases/{base}/tables', 'storeTable');
            Route::get('airtable-tables', 'airtableTables');
            Route::get('repair-split', 'repairSplit');
            Route::get('refunds', 'refunds');
            Route::post('bases/{base}/airtable-links', 'linkAirtable');
            Route::post('airtable-links', 'mirrorAirtable');
            Route::post('tables/{table}/refresh', 'refreshAirtable');
            Route::post('tables/{table}/cut-over', 'cutOver');
            Route::get('tables/{table}', 'showTable');
            Route::patch('tables/{table}', 'updateTable');
            Route::delete('tables/{table}', 'destroyTable');
            Route::get('tables/{table}/export', 'export');
            Route::post('tables/{table}/import', 'import');
            Route::post('tables/{table}/fields', 'storeField');
            Route::patch('fields/{field}', 'updateField');
            Route::delete('fields/{field}', 'destroyField');
            Route::post('tables/{table}/records', 'storeRecord');
            Route::patch('records/{record}', 'updateRecord');
            Route::patch('tables/{table}/records', 'updateRecords');
            Route::delete('records/{record}', 'destroyRecord');
            Route::post('tables/{table}/views', 'storeView');
            Route::patch('views/{view}', 'updateView');
            Route::get('views/{view}/split', 'split');
            Route::delete('views/{view}', 'destroyView');
        });
        /*
         * NO `permission:inventory.items.view` OR `.locations.view` HERE, and
         * the omission is the decision.
         *
         * These two are the module's reference data, not one tab's list. Every
         * form that records a movement has to name an item and a place:
         * `/inventory/items` is read by six panes and `/inventory/locations` by
         * seven, across all four tabs — new purchase order, new sale, new
         * issue, new transfer, and the item pane itself. A blanket gate here
         * refuses the Orders tab in the middle of writing an order.
         *
         * So the Items and Locations grants hide their LISTS in the chip row
         * and do not withhold the rows, which the registry block for this
         * module states in the same words so an administrator is not told
         * otherwise. The endpoint-level fix is a narrow id-and-name picker for
         * the forms; until that exists this is honest rather than decorative.
         */
        Route::apiResource('inventory/items', InventoryItemsController::class);
        Route::apiResource('inventory/locations', InventoryLocationsController::class);

        // Web Management. Sites are the hosts a company owns — a subdomain is
        // its own site with its own token and its own phone number. The token
        // route re-issues that credential and is the only way back from a lost
        // one, because only the hash is stored.
        //
        // `web/plugin` is the plugin itself, zipped on request — authenticated,
        // unlike the public `web/plugin/values` further up this file, because
        // it hands over code rather than a company's data.
        Route::get('web/plugin', [WebSitesController::class, 'downloadPlugin']);

        /*
         * NO TAB GATE ON `web/sites` ITSELF, and it is not an oversight.
         *
         * Both tabs draw the same rail of hosts from `GET web/sites` —
         * seo_tab.dart:143 and widgets_tab.dart:59 — so a gate naming either
         * tab takes the rail away from the other, and the pane behind it is an
         * empty list rather than a refusal anybody can read. It stays on
         * `web_management.view`/`.edit` as before, and the per-record policies
         * keep doing their job.
         *
         * Everything BELOW is single-tab, measured by grepping the client for
         * each endpoint's consumers rather than by reading the URL:
         *   values, content, content/options, token, public-key, primary
         *                                   → seo_tab, page_editor, templates_pane
         *   widgets (show/update/destroy)   → widgets_tab, and nothing else
         */
        Route::apiResource('web/sites', WebSitesController::class)
            ->parameters(['sites' => 'site']);

        Route::middleware('permission:web_management.seo.view')->group(function () {
            Route::post('web/sites/{site}/token', [WebSitesController::class, 'rotateToken']);
            // The browser key. Separate from the token above because they are different
            // credentials with different blast radii — see rotatePublicKey.
            Route::post('web/sites/{site}/public-key', [WebSitesController::class, 'rotatePublicKey']);
            // The site the company's others inherit from, per key, wherever they
            // say nothing themselves. `edit` gates it: choosing the primary changes
            // what every other site publishes.
            Route::post('web/sites/{site}/primary', [WebSitesController::class, 'makePrimary']);
            Route::get('web/sites/{site}/values', [WebValuesController::class, 'index']);
            Route::put('web/sites/{site}/values', [WebValuesController::class, 'update']);
            // Rodrigo's bulk hours editor: review a week edit across websites,
            // then apply exactly what was reviewed. Each site is authorised
            // as `update`, like the values PUT above.
            Route::post('web/hours/review', [WebHoursBatchController::class, 'review']);
            Route::post('web/hours/apply', [WebHoursBatchController::class, 'apply']);
            // The SEO Helper: each page's search title, description and keyphrase,
            // read and written through the site's plugin. Saving authorises
            // `update` on the site, like the values PUT.
            Route::get('web/sites/{site}/seo-helper', [WebSeoHelperController::class, 'index']);
            Route::put('web/sites/{site}/seo-helper/site', [WebSeoHelperController::class, 'updateSite']);
            Route::get('web/sites/{site}/seo-helper/{page}', [WebSeoHelperController::class, 'show'])->whereNumber('page');
            Route::post('web/sites/{site}/seo-helper/{page}/analyze', [WebSeoHelperController::class, 'analyze'])->whereNumber('page');
            Route::put('web/sites/{site}/seo-helper/{page}', [WebSeoHelperController::class, 'update'])->whereNumber('page');
            // The pages and posts on the site itself, read live through the account
            // it handed us at pairing. Nothing stored, so nothing to keep in step.
            Route::get('web/sites/{site}/content', [WebContentController::class, 'index']);

            /*
             * Before the {site}/content/{path} shape anybody adds later: a literal
             * segment registered after a wildcard one is unreachable.
             */
            Route::get('web/sites/{site}/content/options', [WebContentController::class, 'options']);

            /*
             * The city pages, separated out of Pages at Mia's request on
             * 2026-09-18. Read from OUR values rather than from the website, so
             * it answers when the customer's WordPress does not; `?scan=1` is
             * the opt-in that also asks the site which towns it has pages for.
             *
             * Same tab gate as the rest of SEO, and no new permission: a pane
             * inside a tab is not a grant. See `PageKinds` for what makes a
             * page a city page, and the comment on `web/sites` above for why
             * the gate is per route rather than on the resource.
             */
            Route::get('web/sites/{site}/cities', [WebContentController::class, 'cities']);
        });

        Route::middleware('permission:web_management.widgets.view')->group(function () {
            Route::get('web/sites/{site}/widgets', [WebWidgetsController::class, 'show']);
            Route::put('web/sites/{site}/widgets', [WebWidgetsController::class, 'update']);
            Route::delete('web/sites/{site}/widgets', [WebWidgetsController::class, 'destroy']);
            /*
             * Both take a `scope` of `site` or `company`, because a company
             * with 47 websites had no way to say "all of them" and Jose spent
             * a day switching one of them off — see WidgetsController::chat.
             * Registered before nothing and after the resource: neither name
             * collides with a site id, since `{site}` is already bound.
             */
            Route::post('web/sites/{site}/widgets/chat', [WebWidgetsController::class, 'chat']);
            Route::post('web/sites/{site}/widgets/refresh', [WebWidgetsController::class, 'refresh']);
            // The lists [ezbizi_team], [ezbizi_branches] and [ezbizi_store_locator] draw.
            Route::get('web/sites/{site}/team', [WebListsController::class, 'team']);
            Route::put('web/sites/{site}/team', [WebListsController::class, 'saveTeam']);
            Route::get('web/sites/{site}/locations', [WebListsController::class, 'locations']);
            Route::put('web/sites/{site}/locations', [WebListsController::class, 'saveLocations']);
            Route::get('web/sites/{site}/items/{kind}', [WebListsController::class, 'items']);
            Route::put('web/sites/{site}/items/{kind}', [WebListsController::class, 'saveItems']);
        });
        /*
         * The Orders tab owns buying, selling and issuing outright: nothing
         * outside `order_list.dart` and its own panes reads these, so unlike
         * items and locations above they take a blanket gate.
         *
         * Vendors sit here rather than beside items because the only two panes
         * that read them — the vendor form and the purchase-order form — are
         * both Orders panes. A vendor is who you buy from, so it belongs to
         * buying.
         */
        Route::middleware('permission:inventory.orders.view')->group(function () {
            Route::apiResource('inventory/vendors', InventoryVendorsController::class);
            Route::post('inventory/purchase-orders/{purchase_order}/open', [InventoryPurchaseOrdersController::class, 'open']);
            Route::post('inventory/purchase-orders/{purchase_order}/cancel', [InventoryPurchaseOrdersController::class, 'cancel']);
            Route::post('inventory/purchase-orders/{purchase_order}/receives', [InventoryPurchaseOrdersController::class, 'receive']);
            Route::apiResource('inventory/purchase-orders', InventoryPurchaseOrdersController::class)
                ->only(['index', 'store', 'show']);
            Route::apiResource('inventory/issues', InventoryIssuesController::class)
                ->only(['index', 'store']);
            Route::post('inventory/sales/{sale}/open', [InventorySalesController::class, 'open']);
            Route::post('inventory/sales/{sale}/cancel', [InventorySalesController::class, 'cancel']);
            Route::post('inventory/sales/{sale}/pay', [InventorySalesController::class, 'pay']);
            Route::post('inventory/sales/{sale}/fulfillments', [InventorySalesController::class, 'fulfill']);
            Route::apiResource('inventory/sales', InventorySalesController::class)
                ->only(['index', 'store', 'show']);
        });

        /*
         * On-hand is the Stock tab's own answer, so it is gated — and the item
         * pane, which also shows balances, now asks for them only when the
         * caller holds this. A role granted Items but not Stock is being told
         * "manage the catalogue, not the quantities", and an item pane that
         * listed on-hand anyway would spend a grant nobody made.
         */
        Route::middleware('permission:inventory.stock.view')->group(function () {
            Route::get('inventory/stock', [InventoryStockController::class, 'index']);
            // One row per item, grouped server-side (UI queue 43).
            Route::get('inventory/stock/items', [InventoryStockController::class, 'items']);
            // An item's History. Quantities, so the Stock tab's gate, though it
            // hangs off an item: an Items-only role does not see movements.
            Route::get('inventory/items/{item}/movements', [InventoryMovementsController::class, 'index']);
        });

        /*
         * Adjustments and transfers are movements rather than tabs, and are
         * already authorized by `inventory.edit` inside their controllers. They
         * are reached from panes whose tabs are gated above, and a tab gate on
         * top would refuse an adjustment from the item pane, which is where the
         * product puts it.
         */
        // The General tab's one request; gated on that tab.
        Route::get('inventory/summary', [InventorySummaryController::class, 'index'])
            ->middleware('permission:inventory.general.view');
        Route::post('inventory/adjustments', [InventoryAdjustmentsController::class, 'store']);
        Route::post('inventory/transfers', [InventoryTransfersController::class, 'store']);
        /*
         * The two Services chips gate the WRITES only. Both read paths below
         * serve an audience outside the chip that would otherwise gate them,
         * so gating them would refuse somebody doing a job they are entitled
         * to do, and it would do it silently — an empty menu, not an error.
         *
         *  - `GET services` is the catalogue Jobs prices a line out of, in
         *    client/lib/screens/jobs/job_lines_tab.dart. A dispatcher who
         *    never opens the Services screen still has to read it.
         *  - `GET services/groups` and `GET services/labels` are read by the
         *    CATALOGUE chip's own pickers (add_service_pane, service_pane) as
         *    much as by Settings. Behind `services.settings.view` they would
         *    empty the group and label menus for somebody holding Catalogue.
         *
         * Same shape as `hr/people/scope-options`: a route with two audiences
         * keeps its gate beside the split already inside it — here the
         * existing ServicePolicy check, which is still `services.view` — and
         * not in front of the group. Hiding the chip hides the SCREEN; the
         * catalogue stays readable where another module legitimately needs it.
         */
        Route::get('services/groups', [ServiceGroupsController::class, 'index']);
        Route::get('services/labels', [ServiceLabelsController::class, 'index']);

        Route::middleware('permission:services.settings.view')->group(function () {
            Route::post('services/groups', [ServiceGroupsController::class, 'store']);
            Route::delete('services/groups/{id}', [ServiceGroupsController::class, 'destroy']);
            Route::post('services/labels', [ServiceLabelsController::class, 'store']);
            Route::delete('services/labels/{id}', [ServiceLabelsController::class, 'destroy']);
        });

        // Declared before the index/show pair so `services/groups` and
        // `services/labels` are never swallowed by `services/{service}`.
        Route::middleware('permission:services.catalogue.view')->group(function () {
            Route::apiResource('services', ServicesController::class)
                ->only(['store', 'update', 'destroy']);
        });

        Route::apiResource('services', ServicesController::class)
            ->only(['index', 'show']);

        /*
         * Reports — a builder over the ReportMap
         * (docs/superpowers/specs/2026-09-10-reports-design.md).
         *
         * `fields` and `run` are declared BEFORE the apiResource: registered
         * after it, `reports/fields` would be swallowed by `reports/{id}` and
         * arrive as a show() for a report called "fields".
         */
        /*
         * TEMPORARY, and before `reports/{id}` for the same reason `fields` is:
         * a literal segment declared after the wildcard is swallowed by it.
         * Goes when the Experimental report does.
         */
        /*
         * ── GATED ON THE TAB, not just on reports.build ────────────
         *
         * Jose, 2026-09-17, asked for per-tab grants across the product. This
         * is the tab in Reports where that earns its keep: the pane names every
         * person who answered a phone, how long they were on it, and how much
         * of their work became jobs. Somebody who builds reports about jobs has
         * no automatic business reading other people's performance.
         *
         * The Workiz connect route below is ALSO registered as `social/workiz`
         * for Settings > Integrations, and that copy is deliberately left
         * outside this gate: connecting an account from Integrations is a
         * different act by a different person, and it is Settings' own
         * permission that guards it.
         */
        Route::middleware('permission:reports.experimental.view')->group(function () {
            Route::get('reports/experimental/calls', [ExperimentalCallReportController::class, 'show']);

            /*
             * The audio of one call, proxied because RingCentral serves it from
             * a different host and requires our account token - see the action.
             */
            Route::get('reports/experimental/calls/{recording}/recording', [ExperimentalCallReportController::class, 'recording']);

            Route::post('reports/experimental/workiz', [WorkizConnectController::class, 'store']);

            // Hand-set facts the phones cannot say (Jose, 2026-10-05).
            Route::put('reports/experimental/people/tag', [ExperimentalCallReportController::class, 'tag']);
            Route::post('reports/experimental/calls/{call}/convert', [ExperimentalCallReportController::class, 'convert']);
        });

        /*
        | Connecting a data source from Settings > Integrations.
        |
        | Jose, 2026-09-16: Airtable and Workiz should both be connectable by
        | any business from Integrations rather than from the one screen that
        | happens to read them. The Workiz route above stays: it is the same
        | controller, and a branch connecting its own book from the Experimental
        | screen is still a real path. Two routes to one action is cheaper than
        | moving a shipped one and breaking the screen already using it.
        */
        Route::post('social/airtable', [AirtableConnectController::class, 'store']);
        // One token, then tick the bases it reaches (Jose, 2026-10-20).
        Route::post('social/airtable/bases', [AirtableConnectController::class, 'bases']);
        Route::post('social/airtable/connect', [AirtableConnectController::class, 'connect']);
        Route::post('social/airtable/replace-token', [AirtableConnectController::class, 'replaceToken']);
        Route::post('social/workiz', [WorkizConnectController::class, 'store']);
        Route::put('social/workiz/{connection}', [WorkizConnectController::class, 'update']);
        Route::post('social/workiz-web/link', [WorkizWebSessionController::class, 'link']);
        Route::get('social/workiz-web/{connection}/accounts', [WorkizWebSessionController::class, 'accounts']);
        Route::put('social/workiz-web/{connection}/accounts', [WorkizWebSessionController::class, 'map']);
        Route::post('social/ringcentral', [RingCentralConnectController::class, 'store']);

        /*
         * Profit & Loss, read live from a tenant's own Airtable base. Before
         * `reports/{id}` for the same reason as everything above it.
         *
         * `profit_loss.view`, NOT `reports.view` - the sheet carries payroll
         * and owner distributions by name, so it is a separate grant from
         * measuring jobs and calls. Jose's call, 2026-09-17.
         */
        Route::get('reports/profit-loss/sources', [ProfitLossController::class, 'sources']);
        Route::get('reports/profit-loss', [ProfitLossController::class, 'show']);
        // Ad cost against jobs and sales per source; same grant as the P&L.
        Route::get('reports/marketing', [MarketingReportController::class, 'show'])->middleware('permission:reports.marketing.view');
        // Facebook ad spend per campaign (Jose, 2026-10-12), and the campaign
        // mapping (state, category, branch) staff keep for it.
        // Each person's AI call scores, ranked (Jose, 2026-10-13). Performance's
        // grant until the Scoring tab's own lands with its screen.
        // Its own tab (Jose: "right now only administrators and lead manager").
        Route::get('reports/scoring', [ScoringReportController::class, 'show'])->middleware('permission:reports.scoring.view');
        Route::get('reports/scoring/calls', [ScoringReportController::class, 'calls'])->middleware('permission:reports.scoring.view');
        // How much each metric counts (Jose, 2026-10-14); seen with the tab, set with its own permission.
        // The weekly/monthly report's goals (A1 Leads takeover, 2026-10-16).
        // Correcting a report draft before it is approved (2026-10-20).
        Route::get('reports/drafts', [\App\Http\Controllers\Api\ReportDraftsController::class, 'index'])->middleware('permission:reports.correct');
        Route::post('reports/drafts/{batch}/changes', [\App\Http\Controllers\Api\ReportDraftsController::class, 'change'])->middleware('permission:reports.correct');
        Route::get('reports/goals', [\App\Http\Controllers\Api\ReportGoalsController::class, 'index'])->middleware('permission:reports.view');
        Route::put('reports/goals', [\App\Http\Controllers\Api\ReportGoalsController::class, 'update'])->middleware('permission:reports.settings');
        Route::get('reports/scoring/weights', [ScoringReportController::class, 'weights'])->middleware('permission:reports.scoring.view');
        Route::put('reports/scoring/weights', [ScoringReportController::class, 'saveWeights'])->middleware('permission:reports.scoring_weights');
        // A scored call's recording, for Scoring alone (Performance's own needs Performance).
        Route::get('reports/scoring/calls/{recording}/recording', [ExperimentalCallReportController::class, 'recording'])->middleware('permission:reports.scoring.view');
        // Thumbtack lead spend (Jose, 2026-10-13).
        Route::get('reports/thumbtack', [ThumbtackReportController::class, 'show'])->middleware('permission:reports.thumbtack.view');
        Route::middleware('permission:reports.meta.view')->group(function () {
            Route::get('reports/meta', [MetaAdsReportController::class, 'show']);
            Route::get('reports/meta/campaigns', [MetaAdsReportController::class, 'campaigns']);
            Route::put('reports/meta/campaigns/{campaign}', [MetaAdsReportController::class, 'update'])->middleware('permission:reports.build');
        });

        /*
         * ── apiResource SPLIT BY AUDIENCE, deliberately ────────────
         *
         * It used to be one line. A blanket tab gate over it would have been
         * wrong in the way Settings warned about: `index` is the library's and
         * `store` is the builder's, and one middleware cannot say both. So the
         * verbs are registered individually, each under the tab whose screen
         * actually calls it — measured from the client rather than assumed:
         *
         *     reports_screen.dart    GET /reports, DELETE /reports/{id}
         *     report_run_view.dart   POST /reports/{id}/run
         *     report_builder.dart    GET /reports/fields, POST /reports
         *
         * Every action behind these still has its own policy check. The tab
         * grant is a second question — may this role SEE this pane — and it
         * does not replace `reports.build` or the per-report authorisation.
         *
         * `reports/fields` stays ahead of `reports/{id}`: a literal segment
         * declared after the wildcard is swallowed by it, and the symptom is a
         * show() for a report called "fields".
         */
        Route::middleware('permission:reports.builder.view')->group(function () {
            Route::get('reports/fields', [ReportsController::class, 'fields']);
            Route::post('reports', [ReportsController::class, 'store']);
            Route::post('reports/preview', [ReportsController::class, 'preview']);
            Route::match(['put', 'patch'], 'reports/{id}', [ReportsController::class, 'update']);
        });

        Route::middleware('permission:reports.library.view')->group(function () {
            Route::get('reports', [ReportsController::class, 'index']);
            Route::post('reports/{id}/run', [ReportsController::class, 'run']);
            Route::get('reports/{id}/csv', [ReportsController::class, 'csv']);
            Route::get('reports/{id}', [ReportsController::class, 'show']);
            Route::delete('reports/{id}', [ReportsController::class, 'destroy']);
        });

        /*
         * Scheduling — capacity per slot, per weekday, per service area
         * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md,
         * design docs/previews/scheduling-layouts.html #7).
         *
         * `pattern/*` is declared BEFORE the apiResource, because
         * `scheduling/areas/{area}` would otherwise swallow anything nested
         * under it that arrives first. Ordering here is load-bearing, not
         * tidiness.
         */
        /*
         * TWO AUDIENCES, AND NEITHER IS A PERSON READING THEIR OWN DAY.
         *
         * The brief warns that a route serving two audiences cannot take a
         * blanket gate — HR's `onboardings` must answer the employee filling
         * their own form in. Measured here rather than assumed: Scheduling has
         * NO person dimension at all. `2026_08_31_130000_create_scheduling_tables`
         * creates service_areas, service_area_zips and service_area_slots, and
         * none of them carries a user, staff or technician column. There is no
         * "my own schedule" in this module to take away from anybody, so a
         * group gate is safe on every route below.
         *
         * READING what the business can take is the dispatcher's job; CHANGING
         * it is not. That is the split, and it is why availability and settings
         * are separate grants rather than one.
         */
        // The Schedule tab: one day's board, read-only.
        // Scheduling › Overbooking (Jose, 2026-09-29).
        // Scheduling › Locations (Jovanni, 2026-10-01; queue 49).
        Route::middleware('permission:scheduling.locations.view')->group(function () {
            Route::get('scheduling/locations', [SchedulingLocationsController::class, 'index']);
            Route::get('scheduling/locations/nearest', [SchedulingLocationsController::class, 'nearest']);
        });
        Route::middleware('permission:scheduling.settings.view')->group(function () {
            Route::put('scheduling/locations/notes/{company}', [SchedulingLocationsController::class, 'note']);
        });
        Route::middleware('permission:scheduling.helpers.view')->group(function () {
            Route::get('scheduling/helpers', [\App\Http\Controllers\Api\SchedulingHelpersController::class, 'index']);
            Route::post('scheduling/helpers', [\App\Http\Controllers\Api\SchedulingHelpersController::class, 'store']);
            Route::get('scheduling/helpers/bookings', [\App\Http\Controllers\Api\SchedulingHelpersController::class, 'bookings']);
            Route::post('scheduling/helpers/bookings', [\App\Http\Controllers\Api\SchedulingHelpersController::class, 'book']);
            Route::patch('scheduling/helpers/bookings/{booking}', [\App\Http\Controllers\Api\SchedulingHelpersController::class, 'updateBooking']);
            Route::delete('scheduling/helpers/bookings/{booking}', [\App\Http\Controllers\Api\SchedulingHelpersController::class, 'destroyBooking']);
            Route::patch('scheduling/helpers/{helper}', [\App\Http\Controllers\Api\SchedulingHelpersController::class, 'update']);
        });
        Route::get('scheduling/routes', [SchedulingAvailabilityController::class, 'routes'])
            ->middleware('permission:scheduling.routing.view');
        Route::get('scheduling/reschedules', [SchedulingAvailabilityController::class, 'reschedules'])
            ->middleware('permission:scheduling.rescheduling.view');
        Route::middleware('permission:scheduling.overbooking.view')->group(function () {
            Route::get('scheduling/overbooking', [SchedulingOverbookingController::class, 'show']);
            Route::get('scheduling/overbooking/jobs', [SchedulingOverbookingController::class, 'jobs']);
            Route::get('scheduling/reports', [SchedulingOverbookingController::class, 'reports']);
        });

        Route::middleware('permission:scheduling.schedule.view')->group(function () {
            Route::get('scheduling/board', [SchedulingBoardController::class, 'show']);
        });

        // Dispatching writes to Workiz: the Schedule tab AND Scheduling's edit.
        Route::middleware(['permission:scheduling.schedule.view', 'permission:scheduling.edit'])->group(function () {
            Route::post('scheduling/board/jobs/{job}/assign', [SchedulingDispatchController::class, 'assign']);
            Route::post('scheduling/board/jobs/{job}/unassign', [SchedulingDispatchController::class, 'unassign']);
            Route::post('scheduling/board/jobs/{job}/reschedule', [SchedulingDispatchController::class, 'reschedule']);
        });

        Route::middleware('permission:scheduling.availability.view')->group(function () {
            // Polled every 30 s by each open availability pane: an ETag lets an
            // unchanged answer come back as an empty 304 (2026-10-15; done by
            // Monitoring while Scheduling was unstaffed).
            Route::get('scheduling/availability', [SchedulingAvailabilityController::class, 'show'])
                ->middleware('cache.headers:etag');
            Route::get('scheduling/availability/suggestion', [SchedulingAvailabilityController::class, 'suggestion'])
                ->middleware('cache.headers:etag');
            Route::get('scheduling/availability/fit', [SchedulingAvailabilityController::class, 'fit']);

            // Suggest is Availability's search box and has no other caller
            // (grepped across client/lib). It answers with area names, city
            // names and zips — the same facts the week grid shows — so it
            // belongs to the same grant rather than to settings.
            Route::get('scheduling/suggest', [SchedulingSuggestController::class, 'index']);
            Route::get('scheduling/availability/franchises', [SchedulingAvailabilityController::class, 'franchises']);
            Route::get('scheduling/availability/calendar', [SchedulingAvailabilityController::class, 'calendar']);
        });

        /*
         * Everything that WRITES capacity, plus the list that feeds the editor.
         *
         * `pattern/*` is declared BEFORE the apiResource, because
         * `scheduling/areas/{area}` would otherwise swallow anything nested
         * under it that arrives first. Ordering here is load-bearing, not
         * tidiness.
         */
        Route::middleware('permission:scheduling.settings.view')->group(function () {
            // The organization's windows. Writes also need an organization-level
            // assignment — see SchedulingTimeSlotsController.
            Route::get('scheduling/time-slots', [SchedulingTimeSlotsController::class, 'index']);
            Route::post('scheduling/time-slots', [SchedulingTimeSlotsController::class, 'store']);
            Route::patch('scheduling/time-slots/{slot}', [SchedulingTimeSlotsController::class, 'update']);
            Route::delete('scheduling/time-slots/{slot}', [SchedulingTimeSlotsController::class, 'destroy']);
            // Settings › Schedule: Workiz technicians and their EzBizi users.
            Route::get('scheduling/technicians', [SchedulingTechniciansController::class, 'index']);
            Route::put('scheduling/technicians/{member}', [SchedulingTechniciansController::class, 'update']);
            Route::put('scheduling/franchises/{franchise}/limits', [SchedulingFranchiseLimitsController::class, 'update']);
            Route::put('scheduling/areas/{area}/slots', [SchedulingAreasController::class, 'slots']);
            Route::put('scheduling/areas/{area}/dates/{date}', [SchedulingAreasController::class, 'setDate']);
            Route::delete('scheduling/areas/{area}/dates/{date}', [SchedulingAreasController::class, 'clearDate']);
            Route::put('scheduling/areas/{area}/available-from', [SchedulingAreasController::class, 'availableFrom']);
            Route::put('scheduling/areas/{area}/shares-with', [SchedulingAreasController::class, 'sharesWith']);
            Route::put('scheduling/areas/{area}/limits', [SchedulingAreasController::class, 'limits']);
            Route::put('scheduling/areas/{area}/scheduled/{date}', [SchedulingAreasController::class, 'scheduleChange']);
            Route::delete('scheduling/areas/{area}/scheduled/{date}', [SchedulingAreasController::class, 'cancelChange']);
            Route::put('scheduling/areas/{area}/zips', [SchedulingAreasController::class, 'zips']);
            Route::post('scheduling/pattern/preview', [SchedulingPatternController::class, 'preview']);
            Route::post('scheduling/pattern/apply', [SchedulingPatternController::class, 'apply']);
            Route::apiResource('scheduling/areas', SchedulingAreasController::class)
                ->parameters(['areas' => 'area']);
        });

        // Field — docs/superpowers/specs/2026-09-25-field-module-design.md.
        // `me` answers anybody (a phone asks before it knows its role); the
        // phone's writes need `tracked`; every read is one tab's grant.
        Route::get('field/me', [FieldController::class, 'me']);
        Route::middleware('permission:field.tracked')->group(function () {
            Route::post('field/locations', [FieldController::class, 'ingest'])->middleware('throttle:120,1');
            Route::post('field/sos', [FieldController::class, 'sos'])->middleware('throttle:10,1');
        });
        Route::middleware('permission:field.dashboard.view')->group(function () {
            Route::get('field/dashboard', [FieldController::class, 'dashboard']);
        });
        Route::middleware('permission:field.manage')->group(function () {
            Route::post('field/alerts/{alert}/acknowledge', [FieldController::class, 'acknowledge']);
        });
        Route::middleware('permission:field.map.view')->group(function () {
            Route::get('field/map', [FieldController::class, 'map']);
            Route::get('field/places', [FieldController::class, 'places']);
            // Jobs on the map and a dispatcher's ETA also need jobs.view (checked inside).
            Route::get('field/jobs', [FieldController::class, 'jobs']);
            Route::get('field/eta', [FieldController::class, 'eta']);
        });
        // A1's Stats map (with Leads, 2026-10-19): Leads users hold leads.view,
        // not field.map.view, and draw these on their Stats tab.
        Route::middleware('permission:field.map.view,leads.view')->group(function () {
            Route::get('field/closest-office', [FieldController::class, 'closestOffice'])->middleware('throttle:60,1');
            Route::get('field/geocode-zips', [FieldController::class, 'geocodeZips'])->middleware('throttle:30,1');
            Route::get('field/coverage', [FieldController::class, 'coverage']);
            // The map itself, so Leads' Stats map has tiles (no data in them).
            Route::get('field/map/tiles', [FieldController::class, 'tiles']);
            Route::get('field/map/attribution', [FieldController::class, 'attribution']);
        });
        Route::middleware('permission:field.route.view')->group(function () {
            Route::get('field/people', [FieldController::class, 'people']);
            Route::get('field/route', [FieldController::class, 'route']);
        });
        // Safety recordings (from A1 Tools, 2026-10-16). Listing and playback
        // check own-or-field.listen in the controller; keeping, field.manage.
        Route::middleware('permission:field.record')->group(function () {
            Route::post('field/recordings', [FieldRecordingsController::class, 'begin'])->middleware('throttle:30,1');
            Route::post('field/recordings/{recording}/chunks', [FieldRecordingsController::class, 'chunk'])->middleware('throttle:120,1');
            Route::post('field/recordings/{recording}/finish', [FieldRecordingsController::class, 'finish']);
        });
        Route::get('field/recordings', [FieldRecordingsController::class, 'index']);
        Route::get('field/recordings/{recording}/audio/{seq}', [FieldRecordingsController::class, 'audio'])->whereNumber('seq');
        Route::post('field/recordings/{recording}/flag', [FieldRecordingsController::class, 'flag']);

        // Training — guides people read, the tests they take, and (later
        // tasks) who has been given which. `view`/`monitor`/`manage`/
        // `assign` are the module's four permissions; these two tasks wire
        // the guide and the test. List applies the same place-scope engine
        // as Inventory and Services — see GuidesController::index and
        // TestsController::index. Later tasks add assignments and
        // progress/results routes to this same block.
        Route::apiResource('training/guides', TrainingGuidesController::class);
        Route::apiResource('training/tests', TrainingTestsController::class);
        // The learner-facing read (task 6) — `training.view`, not `.manage`;
        // see LearnController's docblock for the answer-key trust boundary.
        Route::get('training/learn', [TrainingLearnController::class, 'index']);
        Route::get('training/learn/tests/{test}', [TrainingLearnController::class, 'showTest']);
        // Marking a submitted test (task 8) — same `training.view` gate; the
        // gate, the pass mark, the retake cap and the expiry date are all
        // decided server-side against the stored test. See
        // LearnController::submit.
        Route::post('training/learn/tests/{test}/attempts', [TrainingLearnController::class, 'submit']);
        // Reading progress through a guide (task 7) — same `training.view`
        // gate and the same place-scope/published-only filter as the reads
        // above; see ProgressController's docblock.
        Route::post('training/learn/guides/{guide}/seen', [TrainingProgressController::class, 'seen']);
        // Giving a guide or a test to a person or a role (task 9) —
        // `training.assign`, bounded by §4 reach; see AssignmentsController
        // and AssignmentReach.
        Route::post('training/assignments', [TrainingAssignmentsController::class, 'store']);
        // Who the Dashboard's "Assign to more people" control may offer
        // (audit-fixes-report.md, closing the missing-control finding) —
        // gated on the same `training.assign` permission as the write
        // above, and never on `hr.view`/`roles.view`: a supervisor holding
        // only `training.assign` still needs somewhere to find a person or
        // role to name, and every other people/role listing endpoint on
        // the platform is gated on an HR or Roles permission this holder
        // may not have. See AssignmentsController::assignees.
        Route::get('training/assignees', [TrainingAssignmentsController::class, 'assignees']);
        // How an organization watches training (task 11) — `training.monitor`,
        // never `.manage` or `.view`. `{id}/people` is declared AFTER the
        // bare dashboard route; the order would only matter if one route
        // were a prefix of the other's own children, which they are not —
        // it stays grouped here so both dashboard routes read together. See
        // DashboardController.
        Route::get('training/dashboard', [TrainingDashboardController::class, 'index']);
        Route::get('training/dashboard/{id}/people', [TrainingDashboardController::class, 'people']);
        // The read HR's screen renders from — same `training.monitor` gate.
        // Training writes nothing into HR's own tables (spec decision 7).
        Route::get('training/records', [TrainingDashboardController::class, 'records']);

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

        // Its own route rather than a key on the PATCH above, which refuses
        // parent_company_id outright: a field that reshapes the organization
        // must not ride along with a phone number.
        Route::patch('companies/{company}/parent', [CompaniesController::class, 'reparent']);

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
        Route::get('places/counties', \App\Http\Controllers\Api\CountyLookupController::class);

        // ── Branding (§12) ───────────────────────────────────────
        //
        // Served through a controller, never as a public file: a logo at a
        // guessable path enumerates every organization on the platform.
        // Franchises have none — a franchise is a geography, not a brand.
        Route::get('branding/shapes', [BrandingController::class, 'shapes']);
        // The colour the business publishes under — read by anybody in the
        // tenant, like the marks above; written with organizations.edit,
        // because it changes what strangers see on the public website.
        Route::get('branding/colour', [BrandingController::class, 'colour']);
        Route::put('branding/colour', [BrandingController::class, 'setColour']);
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
        // Push the scheduled end out by the org's extension length (design 2026-09-08 §7).
        Route::post('time-clock/extend', [TimeClockController::class, 'extend']);
        // The same block /me carries, for the once-a-minute poll (design 2026-09-08 §3.12).
        Route::get('time-clock/me', [TimeClockController::class, 'me']);
        // Address → formatted address + coordinates (Google), for any module's
        // screen; the controller gates it per caller (`for=`).
        Route::get('geocode', \App\Http\Controllers\Api\GeocodeController::class)
            ->middleware('throttle:geocode');
        // Ask for one of your own shifts to be corrected (2026-09-30). Answered
        // under work_hours.manage below.
        Route::get('time-clock/corrections', [TimeClockCorrectionsController::class, 'mine']);
        Route::post('time-clock/corrections', [TimeClockCorrectionsController::class, 'store']);

        /*
         * ── The Work Hours tab, and only this tab ──
         *
         * Per-tab grants, 2026-09-17. The four `time-clock/*` routes ABOVE are
         * deliberately outside this group: they are the punch clock in the top
         * bar, which everybody with an account uses on themselves, and gating
         * them behind an HR tab would stop people clocking in.
         *
         * These two are the pane that shows everybody's week.
         */
        Route::middleware('permission:work_hours.general.view')->group(function () {
            // Work Hours › Dashboard's numbers (2026-10-01). Under General
            // until the Dashboard tab's screen is built; it then moves to
            // `work_hours.dashboard.view`.
            Route::get('hr/time-clock/stats', [TimeClockController::class, 'stats']);

            // Clock in–out chip on Human Resources. `?on=` is a calendar date in
            // the current view timezone (design §9).
            Route::get('hr/time-clock', [TimeClockController::class, 'index']);

            // Work Hours: one week, grouped by person, with totals (2026-09-17).
            Route::get('hr/time-clock/week', [TimeClockController::class, 'week']);
        });

        /*
         * ── Corrections (2026-09-23) ──
         *
         * A SECOND grant on top of the tab, not a wider use of it. Seeing
         * everybody's hours and rewriting what they are paid for are different
         * authorities; `hr.hours.correct` is the one that rewrites, and a
         * dispatcher granted Work Hours to check their own week does not get
         * it by holding the tab.
         *
         * One consumer each — the Work Hours pane — so the middleware is the
         * right place for the gate, and the policy behind it says the same
         * thing for anything that reaches these another way.
         */
        /*
         * AI Assistance (2026-09-24). The AI tab asks the assistant; the
         * Library tab is its answers, read on the tab and written under
         * `ai_assistance.manage` by AssistantEntryPolicy, place by place.
         */
        Route::middleware('permission:ai_assistance.ai.view')->group(function () {
            Route::get('ai-assistance/menu', [AssistantController::class, 'menu']);
            Route::get('ai-assistance/ask', [AssistantController::class, 'ask']);
        Route::get('ai-assistance/answer', [AssistantController::class, 'answer']);
        });
        // Guidelines (2026-10-14): read on the Guidelines tab, written under
        // `guidelines.manage` by GuidelinePolicy, place by place.
        Route::apiResource('guidelines', GuidelinesController::class)->except('show');
        Route::get('guidelines/{guideline}/image', [GuidelinesController::class, 'image']);
        Route::post('guidelines/{guideline}/image', [GuidelinesController::class, 'uploadImage']);
        Route::delete('guidelines/{guideline}/image', [GuidelinesController::class, 'destroyImage']);
        Route::apiResource('ai-assistance/entries', AssistantEntriesController::class)
            ->except('show')
            ->parameters(['entries' => 'entry']);
        // Where each WhatsApp report goes (docs/REPLACING-RODRIGO-BOT.md).
        Route::middleware('permission:ai_assistance.manage')->group(function () {
            Route::apiResource('ai-assistance/whatsapp-groups', WhatsAppGroupsController::class)
                ->except('show')
                ->parameters(['whatsapp-groups' => 'group']);
            Route::post('ai-assistance/whatsapp-groups/{group}/publish', [WhatsAppGroupsController::class, 'publish']);
            // The staff line's menu for each role (UI queue 88).
            Route::get('ai-assistance/staff-bot', [StaffBotMenusController::class, 'index']);
            Route::post('ai-assistance/staff-bot/menus', [StaffBotMenusController::class, 'storeMenu']);
            Route::put('ai-assistance/staff-bot/menus/{menu}', [StaffBotMenusController::class, 'updateMenu']);
            Route::delete('ai-assistance/staff-bot/menus/{menu}', [StaffBotMenusController::class, 'destroyMenu']);
            Route::put('ai-assistance/staff-bot/menus/{menu}/order', [StaffBotMenusController::class, 'order']);
            Route::post('ai-assistance/staff-bot/menus/{menu}/items', [StaffBotMenusController::class, 'storeItem']);
            Route::put('ai-assistance/staff-bot/items/{item}', [StaffBotMenusController::class, 'updateItem']);
            Route::delete('ai-assistance/staff-bot/items/{item}', [StaffBotMenusController::class, 'destroyItem']);
        });

        Route::middleware('permission:work_hours.manage')->group(function () {
            Route::patch('hr/time-clock/shifts/{shift}', [TimeClockController::class, 'update']);
            Route::delete('hr/time-clock/shifts/{shift}', [TimeClockController::class, 'destroy']);
            // Force clock out, and the day's tag / extra hours / note (2026-09-24).
            Route::post('hr/time-clock/shifts/{shift}/clock-out', [TimeClockController::class, 'forceClockOut']);
            Route::put('hr/time-clock/people/{user}/days/{date}', [TimeClockController::class, 'recordDay']);
            // Add shift: a day somebody worked and never punched (2026-10-01).
            Route::post('hr/time-clock/people/{user}/shifts', [TimeClockController::class, 'addShift']);
            Route::get('hr/time-clock/corrections', [TimeClockCorrectionsController::class, 'index']);
            Route::post('hr/time-clock/corrections/{correction}/approve', [TimeClockCorrectionsController::class, 'approve']);
            Route::post('hr/time-clock/corrections/{correction}/deny', [TimeClockCorrectionsController::class, 'deny']);
        });

        /*
         * ── Work Hours › Reports (2026-09-28) ──
         *
         * Saved weekly hours reports, sent by hand here or by the
         * `work_hours.send_report` automation. Reading is the tab; saving,
         * deleting and sending are `manage`, because a report is everybody's
         * hours delivered to an inbox.
         */
        Route::middleware('permission:work_hours.reports.view')->group(function () {
            Route::get('hr/time-clock/reports', [TimeClockReportsController::class, 'index']);
            Route::get('hr/time-clock/reports/{report}/preview', [TimeClockReportsController::class, 'preview']);
        });
        Route::middleware('permission:work_hours.manage')->group(function () {
            Route::post('hr/time-clock/reports', [TimeClockReportsController::class, 'store']);
            Route::patch('hr/time-clock/reports/{report}', [TimeClockReportsController::class, 'update']);
            Route::delete('hr/time-clock/reports/{report}', [TimeClockReportsController::class, 'destroy']);
            Route::post('hr/time-clock/reports/{report}/send', [TimeClockReportsController::class, 'send']);
        });

        // The tab the owner asked for. It computes nothing itself — the figures
        // are widgets in the shared registry, so headcount on this tab and
        // headcount on somebody's main dashboard are the same code.
        Route::middleware('permission:hr.dashboard.view')
            ->get('hr/dashboard', [HrDashboardController::class, 'index']);
        // The redesigned dashboard's one read (2026-10-01): HrOverview.
        Route::middleware('permission:hr.dashboard.view')
            ->get('hr/dashboard/overview', [HrDashboardController::class, 'overview']);

        /*
         * UNGATED, and it is not an oversight: `scope-options` is the INVITE
         * form's helper, not the Records tab's, despite sitting under
         * `hr/people`. Gating it behind `hr.records.view` refused a branch
         * manager with `hr.create` who was inviting somebody, which
         * HrInvitationsTest caught within a minute of the gate landing.
         *
         * It is also self-limiting by construction — it answers with exactly
         * the scopes and roles the CALLER may already assign, which is what its
         * test is named for — so there is nothing behind it that a tab grant
         * would be protecting.
         */
        Route::get('hr/people/scope-options', [HrPeopleController::class, 'scopeOptions']);

        Route::middleware('permission:hr.records.view')
            ->get('hr/people', [HrPeopleController::class, 'index']);

        // One person's whole record — the Records tab's right-hand pane
        // (2026-09-24). Gated like the list: it has exactly one consumer, the
        // Records tab, which is the case 0051 says the middleware is right for.
        // Declared AFTER scope-options, or `{user}` would swallow that path.
        Route::middleware('permission:hr.records.view')
            ->get('hr/people/{user}', [HrPeopleController::class, 'show']);

        // The four lists on a record (2026-09-29): pay rates, emergency contacts,
        // HR notes, tools and equipment. Records is their only consumer. The
        // controller adds UserPolicy::update, and `hr.pay.edit` for pay.
        Route::middleware('permission:hr.records.view')->group(function () {
            Route::post('hr/people/{user}/details/{kind}', [HrPersonDetailsController::class, 'store']);
            Route::patch('hr/people/{user}/details/{kind}/{id}', [HrPersonDetailsController::class, 'update']);
            Route::delete('hr/people/{user}/details/{kind}/{id}', [HrPersonDetailsController::class, 'destroy']);
        });

        // Deliberately NOT behind the hr.view policy the rest of this group
        // leans on: everybody may read their own paid time off, and `hr.view`
        // is what adds everybody else's. The controller makes that split —
        // see HrPeopleController::pto.
        Route::get('hr/pto', [HrPeopleController::class, 'pto']);
        // Before the {user} PATCH is irrelevant — different verbs — but declared
        // beside it so the three read as one resource. §9's consent answer, which
        // lives next to the person rather than in a privacy module of its own.
        Route::get('hr/people/{user}/consents', [HrPeopleController::class, 'consents']);
        Route::patch('hr/people/{user}', [HrPeopleController::class, 'update']);
        // Their weekday schedule (design 2026-09-08 §10.3). Zone is the caller's view.
        Route::patch('hr/people/{user}/schedule', [HrPeopleController::class, 'updateSchedule']);

        // Sign-in recovery, gated by hr.security.reset rather than hr.edit — see
        // UserPolicy::resetSecurity for why clearing somebody's second factor is
        // not the same authority as editing their row.
        Route::post('hr/people/{user}/totp/reset', [HrPeopleController::class, 'resetTotp']);
        Route::post('hr/people/{user}/reset-link', [HrPeopleController::class, 'resetLink']);
        // Permanent deletion, apart from deactivating (Jose, 2026-10-20):
        // what it would remove, then the act. UserPolicy::deletePermanently.
        Route::get('hr/people/{user}/deletion', [HrPeopleController::class, 'deletionPreview']);
        Route::delete('hr/people/{user}', [HrPeopleController::class, 'destroy']);

        Route::middleware('permission:hr.invitations.view')->group(function () {
            Route::get('hr/invitations', [HrInvitationsController::class, 'index']);
            Route::post('hr/invitations', [HrInvitationsController::class, 'store']);
        // Resend and revoke, one at a time. The owner was explicit that there is
        // no bulk form of either, and there is no route here that could become
        // one without somebody adding it deliberately.
            Route::post('hr/invitations/{invitation}/resend', [HrInvitationsController::class, 'resend']);
            Route::delete('hr/invitations/{invitation}', [HrInvitationsController::class, 'destroy']);
        });

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
        /*
         * NO `permission:hr.onboarding.view` HERE, and the reason is the whole
         * shape of this endpoint.
         *
         * An employee with no HR permission at all must reach their OWN
         * onboarding — they are the person filling it in — and
         * HrOnboardingTest pins it: "the employee needs no permission and a
         * stranger needs both", with an empty list rather than a refusal for
         * somebody who has none. A middleware over this route takes a person's
         * own paperwork away from them.
         *
         * So the Onboarding tab's gate belongs inside the controller, beside
         * the split that already exists there, and it is HR's to place. Same
         * carve-out as `hr/pto` twenty lines up. Two routes in this module
         * serve two audiences through one URL, and a blanket gate cannot tell
         * them apart.
         */
        Route::get('hr/onboardings', [HrOnboardingsController::class, 'index']);
        Route::post('hr/onboardings', [HrOnboardingsController::class, 'store']);
        Route::get('hr/onboardings/{onboarding}', [HrOnboardingsController::class, 'show']);
        Route::patch('hr/onboardings/{onboarding}', [HrOnboardingsController::class, 'update']);
        // The row stays. Same shape as withdrawing an invitation: DELETE is
        // the act, cancelled is the status, and the record is how "we started
        // this and changed our mind" remains answerable.
        Route::delete('hr/onboardings/{onboarding}', [HrOnboardingsController::class, 'destroy']);
        Route::post('hr/onboardings/{onboarding}/sign', [HrOnboardingsController::class, 'sign']);
        Route::post('hr/onboardings/{onboarding}/approve', [HrOnboardingsController::class, 'approve']);
        Route::post('hr/onboardings/{onboarding}/preview', [HrOnboardingsController::class, 'preview']);
        Route::post('hr/onboardings/{onboarding}/tax-form', [HrOnboardingsController::class, 'taxForm']);
        Route::post('hr/onboardings/{onboarding}/i9-section2', [HrOnboardingsController::class, 'i9Section2']);
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

        // Compose and publish. `marketing.view` to see what went out,
        // `marketing.edit` to send — the same split Connect uses, because
        // posting as an account is not a lesser act than connecting it.
        //
        // Multipart on the way in: one post carries at most one picture or
        // video, and it is not an `attachments` record — nothing else on the
        // platform hangs files off it, and it leaves when the post does.
        //
        // The send itself is a job per account, so a 201 here means queued and
        // never means posted. The targets it answers say which.
        // ── Newsletter ───────────────────────────────────────────
        //
        // Its own module (`newsletter.*`), drawn inside Marketing. Five
        // permissions rather than two, because `send` has to be grantable on
        // its own: writing a mailing and putting it in front of twelve hundred
        // customers are different acts.
        //
        // Everything files on the VIEW and reads by the same rule connections
        // use — an organization-level newsletter is visible down, a sibling
        // company's never is. Out of view answers 404, not 403, because a
        // sibling learning a mailing exists is itself the leak.
        // The list answers to Dashboard OR Composer, so its tab check lives in
        // NewslettersController::index rather than on the route.
        Route::get('newsletters', [NewslettersController::class, 'index']);

        // Composer's own routes: everything that writes, previews or sends.
        Route::middleware('permission:newsletter.composer.view')->group(function () {
            Route::post('newsletters', [NewslettersController::class, 'store']);
            Route::put('newsletters/{newsletter}', [NewslettersController::class, 'update']);
            Route::delete('newsletters/{newsletter}', [NewslettersController::class, 'destroy']);

            // The preview is the SAME document the send carries, rendered by the
            // same class — see BlockRenderer on why nothing renders twice.
            Route::get('newsletters/{newsletter}/preview', [NewslettersController::class, 'preview']);
            Route::post('newsletters/{newsletter}/send', [NewslettersController::class, 'send']);

            Route::get('newsletter/audiences', [NewsletterAudiencesController::class, 'index']);
            Route::post('newsletter/audiences', [NewsletterAudiencesController::class, 'store']);
        });

        // Mailchimp is connected here and from Settings > Integrations. It is
        // NOT in the social catalogue: `SocialProvider::all()` filters to
        // publishing networks, and "post to Mailchimp" is not an act.
        Route::post('newsletter/mailchimp/connect', [MailchimpConnectController::class, 'store']);
        Route::get('newsletter/mailchimp/lists', [MailchimpConnectController::class, 'lists']);

        // Social Media Management's post tabs. Accounts is gated on the screen
        // only: the connection routes below serve Integrations, Leads, Reports
        // and Newsletter too.
        // The list is drawn by all three post tabs; writing is Compose's;
        // Retry sits on the Dashboard and in Compose's sent list.
        Route::get('social/posts', [SocialPostsController::class, 'index'])
            ->middleware('permission:marketing.dashboard.view,marketing.compose.view,marketing.calendar.view');
        Route::post('social/posts', [SocialPostsController::class, 'store'])
            ->middleware('permission:marketing.compose.view');
        Route::post('social/posts/{post}/targets/{target}/retry', [SocialPostsController::class, 'retry'])
            ->middleware('permission:marketing.dashboard.view,marketing.compose.view');
        // Managing a post after Publish: from the Dashboard's side panel or Compose.
        Route::middleware('permission:marketing.dashboard.view,marketing.compose.view')->group(function () {
            Route::patch('social/posts/{post}', [SocialPostsController::class, 'update']);
            Route::delete('social/posts/{post}', [SocialPostsController::class, 'cancel']);
            Route::post('social/posts/{post}/targets/{target}/dismiss', [SocialPostsController::class, 'dismiss']);
            Route::delete('social/posts/{post}/targets/{target}/remote', [SocialPostsController::class, 'deleteRemote']);
        });
        // Business Profile listings the composer may post to.
        Route::middleware('permission:marketing.compose.view')->group(function () {
            Route::get('social/places', [SocialPostsController::class, 'places']);
            Route::get('social/library', [SocialLibraryController::class, 'index']);
            Route::post('social/library', [SocialLibraryController::class, 'store']);
            Route::patch('social/library/{item}', [SocialLibraryController::class, 'update']);
            Route::delete('social/library/{item}', [SocialLibraryController::class, 'destroy']);
            Route::get('social/library/{item}/file', [SocialLibraryController::class, 'file']);
        });
        // Marketing > QR Codes. Writes also need marketing.edit (controller).
        Route::middleware('permission:marketing.qr_codes.view')->group(function () {
            Route::get('marketing/qr-codes', [QrCodesController::class, 'index']);
            Route::post('marketing/qr-codes', [QrCodesController::class, 'store']);
            Route::put('marketing/qr-codes/{qrCode}', [QrCodesController::class, 'update']);
            // No delete (Jose, 2026-10-06): printed codes are deactivated.
            Route::post('marketing/qr-codes/{qrCode}/deactivate', [QrCodesController::class, 'deactivate']);
            Route::post('marketing/qr-codes/{qrCode}/reactivate', [QrCodesController::class, 'reactivate']);
            Route::get('marketing/qr-codes/{qrCode}/image', [QrCodesController::class, 'image']);
            Route::get('marketing/qr-codes/{qrCode}/stats', [QrCodesController::class, 'stats']);
        });
        Route::patch('social/connections/{connection}/leads-franchise', [SocialConnectionsController::class, 'placeLeads']);
        Route::patch('social/connections/{connection}/business-location', [SocialConnectionsController::class, 'linkBusinessLocation']);
        Route::patch('social/connections/{connection}/label', [SocialConnectionsController::class, 'rename']);
        Route::get('social/connections/{connection}/tiktok-creator', [SocialConnectionsController::class, 'tiktokCreator']);
        Route::delete('social/connections/{connection}', [SocialConnectionsController::class, 'destroy']);
        // Comments on the Instagram account linked to a connected Page
        // (instagram_manage_comments). Gated in the controller like posting.
        Route::get('social/connections/{connection}/instagram-comments', [InstagramCommentsController::class, 'index']);
        Route::post('social/connections/{connection}/instagram-comments/{comment}/replies', [InstagramCommentsController::class, 'reply']);
        Route::post('social/connections/{connection}/instagram-comments/{comment}/hide', [InstagramCommentsController::class, 'hide']);
        Route::delete('social/providers/{provider}/connections', [SocialConnectionsController::class, 'destroyProvider']);
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
        Route::get('phone/connections/subaccounts', [PhoneConnectionsController::class, 'subaccounts'])
            ->middleware('permission:phone.settings.view');

        Route::post('phone/connections', [PhoneConnectionsController::class, 'store']);
        Route::delete('phone/connections/{connection}', [PhoneConnectionsController::class, 'destroy']);

        // How the company runs the phone system it has, as opposed to whether
        // it has one — a company-wide rule, on the row that is already one per
        // company.
        Route::patch('phone/connections/{connection}', [PhoneConnectionsController::class, 'update']);
        // Trust & caller identity. The attestation grade is captured on the
        // call rows; the observation log has no API behind it by necessity.
        // Colleagues messaging each other. Not gated on a permission: both
        // ends are staff of the same organization, and "a colleague you may
        // not message" is not a concept this business has.
        Route::get('staff-messages', [StaffMessagesController::class, 'index']);
        Route::get('staff-messages/unread', [StaffMessagesController::class, 'unread']);
        Route::post('staff-messages', [StaffMessagesController::class, 'store']);

        // Short numbers that reach one person. Twilio has no concept of an
        // extension; the digits are ours to resolve before we dial.
        Route::get('phone/extensions', [PhoneExtensionsController::class, 'index']);
        Route::put('phone/extensions', [PhoneExtensionsController::class, 'update']);

        // The Extensions screen, whose subject is the DIGITS rather than the
        // person: every extension on the account and what it reaches, plus
        // creating and retiring one. `index`/`update` above stay the
        // people-shaped view the Users tab needs.
        Route::get('phone/extensions/directory', [PhoneExtensionsController::class, 'directory'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/extensions/directory', [PhoneExtensionsController::class, 'store'])
            ->middleware('permission:phone.settings.view');
        Route::delete('phone/extensions/{extension}', [PhoneExtensionsController::class, 'destroy'])
            ->middleware('permission:phone.settings.view');

        Route::get('phone/trust/attestation', [PhoneTrustController::class, 'attestation'])
            ->middleware('permission:phone.settings.view');
        Route::get('phone/trust/observations', [PhoneTrustController::class, 'observations'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/trust/observations', [PhoneTrustController::class, 'storeObservation'])
            ->middleware('permission:phone.settings.view');
        Route::delete('phone/trust/observations/{observation}', [PhoneTrustController::class, 'destroyObservation'])
            ->middleware('permission:phone.settings.view');

        Route::get('phone/connections/{connection}/trust', [PhoneConnectionsController::class, 'trustShow'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/connections/{connection}/trust', [PhoneConnectionsController::class, 'trustStore'])
            ->middleware('permission:phone.settings.view');

        // Numbers hang on org / company / franchise. List is grant ∩ reach ∩
        // view-scope; buy/release need phone.edit and a resolved Twilio connection.
        // Literal paths before {number}, or "importable" is captured as an id.
        Route::get('phone/numbers/importable', [PhoneNumbersController::class, 'importable'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/numbers/import', [PhoneNumbersController::class, 'import'])
            ->middleware('permission:phone.settings.view');

        // Shopping for a new line: what Twilio has, and where it can sell.
        // Both are phone.edit — the only reason to look is to buy.
        // What actually happens when somebody rings each line, in the order
        // the renderer evaluates. phone.view: it is a read of our own rows.
        Route::get('phone/call-flow', [PhoneNumbersController::class, 'callFlow'])
            ->middleware('permission:phone.settings.view');

        // What this company's phone service cost. phone.edit, because that is
        // the authority that spends the money in the first place.
        Route::get('phone/usage', [PhoneNumbersController::class, 'usage'])
            ->middleware('permission:phone.settings.view');

        // What a number actually is — Twilio Lookup v2. phone.edit, because a
        // fresh lookup is billed; the cached answer is free.
        Route::get('phone/lookup', [PhoneNumbersController::class, 'lookup'])
            ->middleware('permission:phone.settings.view');

        Route::get('phone/numbers/available', [PhoneNumbersController::class, 'available'])
            ->middleware('permission:phone.settings.view');
        Route::get('phone/numbers/countries', [PhoneNumbersController::class, 'countries'])
            ->middleware('permission:phone.settings.view');

        // What Twilio itself says about one line right now — capabilities and
        // above all whether 911 works. Read live; a stored copy goes stale
        // without anybody being told.
        Route::get('phone/numbers/{number}/carrier', [PhoneNumbersController::class, 'carrier'])
            ->middleware('permission:phone.settings.view');

        // Emergency addresses (spec §6). Account-level on Twilio, because one
        // premises answers for every line in the building.
        Route::get('phone/addresses', [PhoneAddressesController::class, 'index'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/addresses', [PhoneAddressesController::class, 'store'])
            ->middleware('permission:phone.settings.view');
        Route::get('phone/numbers', [PhoneNumbersController::class, 'index']);
        Route::post('phone/numbers', [PhoneNumbersController::class, 'store']);

        // How a line behaves — above all which group answers it. Without this
        // `ring_group_id` could never be set and every inbound call fell
        // through to voicemail.
        // Hand a line to a different company. Organization view only — the
        // controller refuses elsewhere, because a company view cannot see the
        // company it would be moving to.
        Route::post('phone/numbers/{number}/move', [PhoneNumbersController::class, 'move'])
            ->middleware('permission:phone.settings.view');

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
        Route::get('phone/members', [PhoneRingGroupsController::class, 'members'])
            ->middleware('permission:phone.settings.view');

        // Ring groups: who a line rings, and where unanswered calls go.
        Route::get('phone/ring-groups', [PhoneRingGroupsController::class, 'index']);
        Route::post('phone/ring-groups', [PhoneRingGroupsController::class, 'store']);
        Route::get('phone/ring-groups/{group}', [PhoneRingGroupsController::class, 'show']);
        Route::patch('phone/ring-groups/{group}', [PhoneRingGroupsController::class, 'update']);
        Route::delete('phone/ring-groups/{group}', [PhoneRingGroupsController::class, 'destroy']);

        // Port-in: start via gateway; status webhooks update phone_port_requests.
        // Reading them was never possible: a port could be started, its
        // status updated by webhook for weeks, and no screen could show it.
        // Voicemail boxes: the greeting, not the messages. Recordings live on
        // the call rows and keep their own retention there.
        Route::get('phone/voicemail-boxes', [PhoneVoicemailBoxesController::class, 'index']);
        Route::post('phone/voicemail-boxes', [PhoneVoicemailBoxesController::class, 'store']);
        Route::patch('phone/voicemail-boxes/{box}', [PhoneVoicemailBoxesController::class, 'update']);
        Route::delete('phone/voicemail-boxes/{box}', [PhoneVoicemailBoxesController::class, 'destroy']);

        // Callers the company will not take a call from. The list is read
        // here; it is ENFORCED on the public voice webhook, before anything
        // routes, because a block that only filtered a screen would still
        // ring every phone in the ring group.
        Route::get('phone/blocked-numbers', [PhoneBlockedNumbersController::class, 'index']);
        Route::post('phone/blocked-numbers', [PhoneBlockedNumbersController::class, 'store']);
        Route::delete('phone/blocked-numbers/{blocked}', [PhoneBlockedNumbersController::class, 'destroy']);

        Route::get('phone/ports', [PhonePortsController::class, 'index'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/ports', [PhonePortsController::class, 'store'])
            ->middleware('permission:phone.settings.view');

        // Named open-hours schedules on a connection. Create may take a number
        // so franchise lines default timezone from franchises.timezone.
        // Call menus. One menu per flow — TwimlRenderer reads the first it
        // finds, so an editor that drew a tree would draw one that never runs.
        // What a call was about. Editing the list is phone.edit; putting one
        // ON a call is not — the people who answer the phone are usually
        // view-plus-a-line-grant, and they are who does the tagging.
        // Reports.
        Route::post('phone/audio', [\App\Http\Controllers\Api\Phone\AudioController::class, 'store'])
            ->middleware('permission:phone.settings.view');
        Route::get('phone/reports/run', [PhoneReportsController::class, 'run'])
            ->middleware('permission:phone.reports.view');
        Route::get('phone/reports', [PhoneReportsController::class, 'index'])
            ->middleware('permission:phone.reports.view');
        Route::post('phone/reports', [PhoneReportsController::class, 'store'])
            ->middleware('permission:phone.reports.view');
        Route::delete('phone/reports/{report}', [PhoneReportsController::class, 'destroy'])
            ->middleware('permission:phone.reports.view');

        Route::get('phone/tags', [PhoneCallTagsController::class, 'index']);
        Route::post('phone/tags', [PhoneCallTagsController::class, 'store']);
        Route::patch('phone/tags/{tag}', [PhoneCallTagsController::class, 'update']);
        Route::delete('phone/tags/{tag}', [PhoneCallTagsController::class, 'destroy']);
        Route::patch('phone/calls/{call}/tag', [PhoneCallTagsController::class, 'tagCall']);

        Route::get('phone/flows', [PhoneFlowsController::class, 'index'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/flows', [PhoneFlowsController::class, 'store'])
            ->middleware('permission:phone.settings.view');
        Route::patch('phone/flows/{flow}', [PhoneFlowsController::class, 'update'])
            ->middleware('permission:phone.settings.view');
        Route::delete('phone/flows/{flow}', [PhoneFlowsController::class, 'destroy'])
            ->middleware('permission:phone.settings.view');

        Route::get('phone/hours', [PhoneHoursController::class, 'index'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/hours', [PhoneHoursController::class, 'store'])
            ->middleware('permission:phone.settings.view');
        Route::get('phone/hours/{schedule}', [PhoneHoursController::class, 'show'])
            ->middleware('permission:phone.settings.view');
        Route::patch('phone/hours/{schedule}', [PhoneHoursController::class, 'update'])
            ->middleware('permission:phone.settings.view');
        Route::delete('phone/hours/{schedule}', [PhoneHoursController::class, 'destroy'])
            ->middleware('permission:phone.settings.view');

        // Voice SDK token + desk presence. phone.view and a granted number on
        // that connection; no grant → fixed 403 sentence.
        Route::post('phone/tokens', [PhoneTokensController::class, 'store']);
        Route::get('phone/presence', [PhonePresenceController::class, 'index']);
        Route::put('phone/presence', [PhonePresenceController::class, 'update']);

        // Records: calls on granted numbers. Playback streams Twilio media.
        // Ring my own handset, then the customer. The only way to place a
        // call from a platform with no Twilio Voice SDK, which is every one
        // of them except a browser.
        // Availability as a fact about the PERSON, so the app bar can show and
        // change it without knowing which connections they are on.
        Route::get('phone/presence/me', [PhonePresenceController::class, 'mine']);
        Route::put('phone/presence/me', [PhonePresenceController::class, 'setMine']);

        Route::post('phone/calls/bridge', [PhoneCallsController::class, 'bridge']);

        /*
        | Move the call I am on to my other devices. No {call} in the path on
        | purpose — a browser knows its own leg's SID and the row is keyed on
        | the customer's, so the server finds the call rather than trusting a
        | client to name one it cannot see.
        */
        Route::post('phone/calls/transfer', [PhoneCallsController::class, 'transfer']);

        // Park sets a live call down; parked lists who is waiting. Picking
        // one up is not a route: the desk dials 'park:<queue>' like any other
        // destination, and the voice webhook bridges it.
        // "I have dealt with this call here; stop my other phones." Answering
        // and declining both post it, because the rule is the same and two
        // endpoints would be two chances to end the wrong leg.
        Route::post('phone/calls/silence', [PhoneCallsController::class, 'silence']);

        Route::post('phone/calls/park', [PhoneCallsController::class, 'park']);
        Route::post('phone/page', [PhoneCallsController::class, 'page']);
        Route::get('phone/calls/parked', [PhoneCallsController::class, 'parked']);

        // Pickup: what is ringing elsewhere, and taking one. {call} last, so
        // the literal paths above cannot be swallowed by the parameter.
        Route::get('phone/calls/ringing', [PhoneCallsController::class, 'ringing']);

        // Who a ringing number belongs to, for the incoming pop-up. Asked by
        // the app SHELL rather than by Communication, because the pop-up
        // appears on top of whatever module is open.
        Route::get('phone/caller', [PhoneCallsController::class, 'caller']);
        Route::get('phone/call-flow/live', [PhoneCallsController::class, 'live'])
            ->middleware('permission:phone.settings.view');
        Route::post('phone/calls/{call}/pickup', [PhoneCallsController::class, 'pickUp']);

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
        Route::get('inspections/catalogue', [InspectionDefinitionsController::class, 'catalogue'])
            ->middleware('permission:inspection.builder.view');

        // The building-code library. Before `{inspection_definition}`, which
        // would otherwise happily bind the word "codes".
        Route::get('inspections/codes', [InspectionCodesController::class, 'index'])
            ->middleware('permission:inspection.builder.view');
        Route::post('inspections/codes', [InspectionCodesController::class, 'store'])
            ->middleware('permission:inspection.builder.view');
        Route::get('inspections/codes/{inspection_code}', [InspectionCodesController::class, 'show'])
            ->middleware('permission:inspection.builder.view');
        Route::patch('inspections/codes/{inspection_code}', [InspectionCodesController::class, 'update'])
            ->middleware('permission:inspection.builder.view');
        Route::delete('inspections/codes/{inspection_code}', [InspectionCodesController::class, 'destroy'])
            ->middleware('permission:inspection.builder.view');
        Route::get('inspections/search', [InspectionRunsController::class, 'search'])
            ->middleware('permission:inspection.new.view');
        Route::get('inspections/startable', [InspectionRunsController::class, 'startable'])
            ->middleware('permission:inspection.new.view');
        Route::get('inspections', [InspectionDefinitionsController::class, 'index'])
            ->middleware('permission:inspection.builder.view');
        Route::post('inspections', [InspectionDefinitionsController::class, 'store'])
            ->middleware('permission:inspection.builder.view');
        Route::get('inspections/reports', [InspectionReportsController::class, 'index'])
            ->middleware('permission:inspection.reports.view');
        // Before `{inspection_report}` would otherwise be happy to bind `pdf`,
        // the same way `catalogue` sits ahead of `{inspection_definition}`.
        Route::get('inspections/reports/{inspection_report}/pdf', [InspectionReportsController::class, 'pdf'])
            ->middleware('permission:inspection.reports.view');
        Route::get('inspections/reports/{inspection_report}', [InspectionReportsController::class, 'show'])
            ->middleware('permission:inspection.reports.view');
        Route::patch('inspections/reports/{inspection_report}', [InspectionReportsController::class, 'update'])
            ->middleware('permission:inspection.reports.view');
        Route::post('inspections/reports/{inspection_report}/archive', [InspectionReportsController::class, 'archive'])
            ->middleware('permission:inspection.reports.view');
        Route::post('inspections/reports/{inspection_report}/unarchive', [InspectionReportsController::class, 'unarchive'])
            ->middleware('permission:inspection.reports.view');
        Route::post('inspections/runs', [InspectionRunsController::class, 'store'])
            ->middleware('permission:inspection.new.view');
        Route::get('inspections/runs/{inspection_run}', [InspectionRunsController::class, 'show'])
            ->middleware('permission:inspection.new.view');
        Route::patch('inspections/runs/{inspection_run}', [InspectionRunsController::class, 'update'])
            ->middleware('permission:inspection.new.view');
        Route::post('inspections/runs/{inspection_run}/restart', [InspectionRunsController::class, 'restart'])
            ->middleware('permission:inspection.new.view');
        Route::post('inspections/runs/{inspection_run}/submit', [InspectionRunsController::class, 'submit'])
            ->middleware('permission:inspection.new.view');
        Route::delete('inspections/runs/{inspection_run}', [InspectionRunsController::class, 'destroy'])
            ->middleware('permission:inspection.new.view');
        Route::post('inspections/{inspection_definition}/cover', [InspectionDefinitionsController::class, 'storeCover'])
            ->middleware('permission:inspection.builder.view');
        Route::get('inspections/{inspection_definition}/cover', [InspectionDefinitionsController::class, 'showCover'])
            ->middleware('permission:inspection.builder.view');
        Route::post('inspections/{inspection_definition}/publish', [InspectionDefinitionsController::class, 'publish'])
            ->middleware('permission:inspection.builder.view');
        Route::post('inspections/{inspection_definition}/unpublish', [InspectionDefinitionsController::class, 'unpublish'])
            ->middleware('permission:inspection.builder.view');
        Route::get('inspections/{inspection_definition}', [InspectionDefinitionsController::class, 'show'])
            ->middleware('permission:inspection.builder.view');
        Route::patch('inspections/{inspection_definition}', [InspectionDefinitionsController::class, 'update'])
            ->middleware('permission:inspection.builder.view');
        Route::delete('inspections/{inspection_definition}', [InspectionDefinitionsController::class, 'destroy'])
            ->middleware('permission:inspection.builder.view');

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
        /*
         * ── `GET email/mailboxes` TAKES NO TAB GATE, AND THAT IS NOT AN
         *    OVERSIGHT ─────────────────────────────────────────────
         *
         * It serves BOTH tabs. Settings lists the connections to manage them;
         * the Inbox calls the same endpoint to draw its rail of inboxes and to
         * learn which addresses this person may read at all
         * (`_loadMailboxes` in `email_screen.dart`). Putting
         * `permission:email.settings.view` in front of it would empty the mail
         * screen for every role that reads mail and does not manage
         * connections — which is the ordinary case and the whole reason the
         * two tabs were split.
         *
         * Its authority is unchanged and already inside the controller:
         * `email.view` for the tab, then grant and §4 reach per inbox, all
         * asked of App\Support\Email\MailboxAccess. This is the shape the HR
         * brief names — a route with two audiences gates beside the split
         * already in the controller, never in front of it.
         */
        Route::get('email/mailboxes', [MailboxesController::class, 'index']);

        /*
         * Everything that CHANGES a connection is the Settings tab. These are
         * the screen where somebody attaches a credential to an address, and
         * where the per-role inbox grants are given out — including, for an
         * administrator, to their own role.
         */
        Route::middleware('permission:email.settings.view')->group(function () {
            Route::post('email/mailboxes', [MailboxesController::class, 'store']);
            Route::post('email/mailboxes/workspace-batch', [MailboxesController::class, 'storeWorkspaceBatch']);

            // The new-email notification, edited from the inbox's card. The
            // automation rule stays the source of truth: MailboxesController.
            Route::get('email/mailboxes/new-mail-alerts', [MailboxesController::class, 'newMailAlerts']);
            Route::put('email/mailboxes/{mailbox}/new-mail-alert', [MailboxesController::class, 'setNewMailAlert']);

            Route::patch('email/mailboxes/{mailbox}', [MailboxesController::class, 'update']);
            Route::delete('email/mailboxes/{mailbox}', [MailboxesController::class, 'destroy']);

            // Who can open an inbox, person by person (2026-10-06). Role grants
            // stay with the roles editor; see MailboxesController::people.
            Route::get('email/mailboxes/{mailbox}/people', [MailboxesController::class, 'people']);
            Route::post('email/mailboxes/{mailbox}/people', [MailboxesController::class, 'givePerson']);
            Route::delete('email/mailboxes/{mailbox}/people/{user}', [MailboxesController::class, 'takeFromPerson']);
            Route::post('email/mailboxes/{mailbox}/connect', [MailboxesController::class, 'connect']);
        });

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
        Route::middleware('permission:email.settings.view')->group(function () {
            Route::post('email/mailboxes/{mailbox}/oauth/start',
                [GoogleMailAuthController::class, 'start']);
            Route::get('email/mailboxes/{mailbox}/oauth/status',
                [GoogleMailAuthController::class, 'status']);
        });

        // ── Connecting a Google Workspace domain, then picking inboxes ──
        //
        // A Workspace administrator proves they run the domain. Shared
        // addresses are then picked from the Directory. The callback is the
        // same public Google redirect as per-inbox sign-in — one registered
        // URI — and GoogleMailAuthController tries the Workspace sign-in row
        // first.
        Route::middleware('permission:email.settings.view')->group(function () {
            Route::get('email/workspace', [GoogleWorkspaceAuthController::class, 'show']);
            Route::get('email/workspace/directory', [GoogleWorkspaceAuthController::class, 'directory']);
            Route::delete('email/workspace', [GoogleWorkspaceAuthController::class, 'destroy']);
            Route::post('email/workspace/oauth/start', [GoogleWorkspaceAuthController::class, 'start']);
            Route::get('email/workspace/oauth/status', [GoogleWorkspaceAuthController::class, 'status']);
        });

        /*
         * ── READING THE MAIL IS THE INBOX TAB ────────────────────
         *
         * Everything below answers about MESSAGES, which is the whole of what
         * the Inbox tab draws. A role granted Settings and not Inbox manages
         * the connections and cannot read what arrives at them -- which is the
         * split Jose asked for, and it is only real if the endpoints enforce
         * it. A hidden tab whose routes still answer is a decoration, and the
         * URL is one paste away from anybody who has ever seen the pane.
         *
         * The per-inbox grant and §4 reach are UNCHANGED and still asked
         * inside the controller. This gate is the door; those decide whose
         * mail is behind it.
         */
        Route::middleware('permission:email.inbox.view')->group(function () {
            Route::get('email/mailboxes/{mailbox}/folders', [MailboxMessagesController::class, 'folders']);
            Route::get('email/mailboxes/{mailbox}/messages', [MailboxMessagesController::class, 'index']);
            Route::post('email/mailboxes/{mailbox}/messages', [MailboxMessagesController::class, 'store']);
            Route::post('email/mailboxes/{mailbox}/messages/bulk', [MailboxMessagesController::class, 'bulk']);
            Route::get('email/mailboxes/{mailbox}/messages/{message}', [MailboxMessagesController::class, 'show']);
            Route::get('email/mailboxes/{mailbox}/messages/{message}/picture',
                [MailboxMessagesController::class, 'picture']);
            Route::get('email/mailboxes/{mailbox}/messages/{message}/file',
                [MailboxMessagesController::class, 'file']);
            Route::post('email/mailboxes/{mailbox}/messages/{message}/reply',
                [MailboxMessagesController::class, 'reply']);
            Route::post('email/mailboxes/{mailbox}/messages/{message}/dealt',
                [MailboxMessagesController::class, 'markDealt']);
            Route::delete('email/mailboxes/{mailbox}/messages/{message}/dealt',
                [MailboxMessagesController::class, 'unmarkDealt']);
            Route::post('email/mailboxes/{mailbox}/messages/{message}/seen',
                [MailboxMessagesController::class, 'markSeen']);
            Route::delete('email/mailboxes/{mailbox}/messages/{message}/seen',
                [MailboxMessagesController::class, 'unsee']);
            Route::delete('email/mailboxes/{mailbox}/messages/{message}',
                [MailboxMessagesController::class, 'destroy']);
        });

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
        /*
         * THE BUILD TAB'S OWN ENDPOINTS, gated as the tab is.
         *
         * These three are what the builder is made of and nothing else uses
         * them: the catalogue is a map of every trigger and action this
         * installation can perform, `trigger-fields` reports what an event
         * carries, and the templates list is Meta's approved set. A hidden tab
         * whose endpoints still answer is a decoration, and the URL is one paste
         * away from anybody who has ever seen the pane.
         *
         * NOT APPLIED TO THE REST OF THE BLOCK. `index`, `show`, `runs`,
         * `update` and `destroy` serve the LIST tab — switching a rule off and
         * reading its runs are things a person may do without being allowed to
         * write one — and they already answer to AutomationPolicy. A blanket
         * middleware here would take the list away from everybody who was only
         * ever meant to lose the builder.
         */
        Route::middleware('permission:automations.build.view')->group(function () {
            Route::get('automations/catalogue', [AutomationsController::class, 'catalogue']);
            // Before the resource for the same reason catalogue is —
            // `trigger-fields` would otherwise bind as an automation id and 404.
            Route::get('automations/trigger-fields', [AutomationsController::class, 'triggerFields']);
            // Build's step screens (UI/UX, 2026-10-20). Before the resource, same trap.
            Route::get('automations/trigger-stats', [AutomationsController::class, 'triggerStats']);
            Route::post('automations/would-have-run', [AutomationsController::class, 'wouldHaveRun']);
            // Before the resource for the third time, same trap.
            Route::get('automations/whatsapp-templates', WhatsAppTemplatesController::class);
        });
        Route::get('automations/{automation}/runs', [AutomationsController::class, 'runs']);
        Route::post('automations/{automation}/test', [AutomationsController::class, 'test']);
        // Separate from `test` on purpose: that one rehearses and performs
        // nothing, this one really sends. A flag shared between them would put
        // a real send one wrong argument away from a rehearsal.
        Route::post('automations/{automation}/run-now', [AutomationsController::class, 'runNow']);
        Route::post('automations/{automation}/activate/{version}', [AutomationsController::class, 'activateVersion']);
        // A copy, switched off; see AutomationsController::duplicate.
        Route::post('automations/{automation}/duplicate', [AutomationsController::class, 'duplicate']);
        Route::apiResource('automations', AutomationsController::class)->except(['store'])
            ->parameters(['automations' => 'automation']);
        Route::post('automations', [AutomationsController::class, 'store']);

        // ── Monitoring: the devices roster (2026-08-28-monitoring-pc-specs) ──
        //
        // Deliberately in THIS group rather than the heartbeat's idle-less one
        // above (routes/api.php ~line 1552): an admin reading this tab IS
        // navigation, and SlideIdleWindow should treat it as such. The
        // heartbeat is a background beat with no screen behind it, which is
        // the one case idle must NOT extend on.
        Route::get('monitoring/devices', [MonitoringDevicesController::class, 'index']);

        // ── Monitoring: screenshots read side (2026-08-31-monitoring-screenshots, Task 4) ──
        //
        // Same idle reasoning as the devices roster just above: an admin
        // reading captures IS navigation. The upload route
        // (`POST monitoring/screenshots`) stays in the idle-less group with
        // the heartbeat instead — see ScreenshotsController's docblock.
        Route::get('monitoring/screenshots', [MonitoringScreenshotsController::class, 'index']);
        Route::get('monitoring/screenshots/{id}', [MonitoringScreenshotsController::class, 'show']);

        // ── Monitoring: Live View, the viewer's half (2026-09-02-monitoring-live-view) ──
        //
        // Same idle reasoning again: a manager opening a live view, and the
        // re-POSTs that keep it open while they watch, ARE navigation. The
        // agent's half — `live-wanted` and `live-frame` — is a background
        // loop with no screen behind it, so it stays in the idle-less group
        // below with the heartbeat instead. See LiveViewController's docblock.
        Route::post('monitoring/devices/{id}/watch', [MonitoringLiveViewController::class, 'watch']);
        Route::get('monitoring/devices/{id}/live-frame', [MonitoringLiveViewController::class, 'liveFrameShow']);

        // ── Monitoring: Remote Control, the viewer's half (2026-09-02-monitoring-remote-control) ──
        //
        // Same idle reasoning as `watch` just above: a manager opening a
        // control session, and the input they send while driving it, ARE
        // navigation. The agent's poll — `input-pending` — is a background
        // loop with no screen behind it, so it stays in the idle-less group
        // below with the heartbeat instead. See RemoteControlController's
        // docblock.
        Route::post('monitoring/devices/{id}/control', [MonitoringRemoteControlController::class, 'control']);
        Route::post('monitoring/devices/{id}/input', [MonitoringRemoteControlController::class, 'input']);

        // ── Monitoring: remote file access, the manager's half (2026-10-01-monitoring-files) ──
        //
        // Same idle reasoning as control just above: a manager browsing and
        // downloading files IS navigation — the request and the poll that
        // collects its answer both keep the session's idle window open. The
        // agent's poll lives in the idle-less group below. See FilesController.
        // One authenticator code opens Files for 15 sliding minutes (Jose, 2026-10-12).
        Route::post('monitoring/files/unlock', [MonitoringFilesController::class, 'unlock']);
        Route::delete('monitoring/files/unlock', [MonitoringFilesController::class, 'lock']);
        Route::post('monitoring/devices/{id}/files', [MonitoringFilesController::class, 'request']);
        Route::get('monitoring/devices/{id}/files/{requestId}', [MonitoringFilesController::class, 'result']);

        // ── Monitoring: cameras (2026-09-01-monitoring-cameras, generic-protocol v1) ──
        //
        // A normal admin action (register/edit/remove a camera config), not a
        // background beat, so all four routes stay in THIS idle-bearing group —
        // see CamerasController's docblock.
        Route::get('monitoring/cameras', [MonitoringCamerasController::class, 'index']);
        Route::post('monitoring/cameras', [MonitoringCamerasController::class, 'store']);
        Route::patch('monitoring/cameras/{id}', [MonitoringCamerasController::class, 'update']);
        Route::delete('monitoring/cameras/{id}', [MonitoringCamerasController::class, 'destroy']);
        Route::post('monitoring/cameras/{id}/watch', [CameraRelayController::class, 'watch']);

        // ── Monitoring: camera sites + pairing a bridge PC (2026-10-20 camera bridge, phase 1) ──
        Route::get('monitoring/camera-sites', [CameraSitesController::class, 'index']);
        Route::post('monitoring/camera-sites', [CameraSitesController::class, 'store']);
        Route::get('monitoring/camera-sites/{id}', [CameraSitesController::class, 'show']);
        Route::patch('monitoring/camera-sites/{id}', [CameraSitesController::class, 'update']);
        Route::delete('monitoring/camera-sites/{id}', [CameraSitesController::class, 'destroy']);
        Route::post('monitoring/camera-sites/{id}/pairing-code', [CameraSitesController::class, 'pairingCode']);
        Route::post('monitoring/camera-sites/{id}/revoke', [CameraSitesController::class, 'revoke']);
        Route::post('monitoring/camera-sites/{id}/cameras', [CameraSitesController::class, 'addCamera']);
        Route::post('monitoring/bridge/pair', [CameraSitesController::class, 'pair']);

        // ── Monitoring: floor plans, the General tab's read side (2026-09-09-monitoring-general-map, Task 3) ──
        //
        // A manager opening the map IS navigation, so the reads stay in THIS
        // idle-bearing group next to the cameras above. The writes (Task 4) are
        // admin edits, not a background beat, so they live here too. `index` is
        // scoped by ScopeReach; `show`/`update`/`destroy` §3-scoped with a manual
        // find + 404; `store`/`update` validate the chosen scope with
        // ScopeReach::assertCovers and every node assignee against
        // constrainToVisiblePeople — see FloorPlansController.
        /*
         * GATED ON THE MAP TAB. The floor plan moved out of General on
         * 2026-09-22 when General became the ownership chart, so these follow
         * the pane rather than the old key.
         *
         * Gated on the tab because these six serve exactly one — measured, not
         * assumed: `grep -rn "floor-plans" client/lib` answers general_tab.dart
         * and floor_plan_canvas.dart, and nothing else in the app reads a floor
         * plan. Where a Monitoring route feeds several panes (`monitoring/devices`
         * is read by PC Specs, General AND Desktop Monitoring) the tab gate would
         * be wrong and the screen filters instead.
         *
         * A hidden tab whose endpoints still answer is decoration rather than a
         * permission: the URL is one paste away from anybody who has ever had the
         * pane open.
         */
        /*
         * The ownership chart — Monitoring's General tab. Gated on that tab
         * because it serves exactly one pane (`grep -rn "monitoring/ownership"
         * client/lib` answers ownership_tab.dart alone), and the two columns
         * that are not Monitoring's own are withheld per COLUMN inside the
         * controller rather than by this gate: PC name needs
         * monitoring.specs.view, and the work schedule needs HR's own
         * hr.hours.view, because it is HR's disclosure to make.
         */
        Route::middleware('permission:monitoring.general.view')->group(function () {
            Route::get('monitoring/ownership', [MonitoringOwnershipController::class, 'index']);
            // The free desks the chart offers when a Desk cell is empty.
            Route::get('monitoring/ownership/desks', [MonitoringOwnershipController::class, 'desks']);
        });

        /*
         * Renaming ONE desk, from the ownership chart.
         *
         * Outside the Map TAB group on purpose: the person doing this is
         * looking at General, and an administrator may reasonably grant "may
         * edit the map" without granting the map's own tab. The edit grant
         * itself (monitoring.map.manage) is enforced by the policy inside,
         * along with the §3 scope check every other plan route makes.
         */
        Route::put(
            'monitoring/floor-plans/{id}/nodes/{node}/label',
            [MonitoringFloorPlansController::class, 'renameNode']
        );
        // Seating somebody at a desk, or clearing it, from the same chart.
        Route::put(
            'monitoring/floor-plans/{id}/nodes/{node}/user',
            [MonitoringFloorPlansController::class, 'assignNode']
        );

        Route::middleware('permission:monitoring.map.view')->group(function () {
        Route::get('monitoring/floor-plans', [MonitoringFloorPlansController::class, 'index']);
        Route::post('monitoring/floor-plans', [MonitoringFloorPlansController::class, 'store']);
        // BEFORE the {id} GET, deliberately: registered after it, "scope-options"
        // would match {id} first and 404 as a plan that does not exist. Gated on
        // monitoring.map.manage (the policy's `create`) and answered straight
        // from ScopeReach::optionsFor, so the create panel offers exactly the
        // levels `store`'s assertCovers will accept.
        Route::get('monitoring/floor-plans/scope-options', [MonitoringFloorPlansController::class, 'scopeOptions']);
        Route::get('monitoring/floor-plans/{id}', [MonitoringFloorPlansController::class, 'show']);
        Route::put('monitoring/floor-plans/{id}', [MonitoringFloorPlansController::class, 'update']);
        Route::delete('monitoring/floor-plans/{id}', [MonitoringFloorPlansController::class, 'destroy']);
        });

        // The assign picker's own roster: who this editor may put on a tile.
        // Deliberately NOT hr/people — that is gated on HR grants a map editor
        // need not hold. Gated on `monitoring.map.manage` (the policy's `create`)
        // and filtered by ScopeReach::constrainToVisiblePeople, the same rule the
        // write endpoint refuses an unseeable assignee by.
        Route::get('monitoring/assignable-people', [MonitoringFloorPlansController::class, 'assignablePeople']);

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
        // The signer's own saved drawn signature (2026-10-01).
        Route::get('me/signature', [\App\Http\Controllers\Api\MySignatureController::class, 'show']);
        Route::put('me/signature', [\App\Http\Controllers\Api\MySignatureController::class, 'update']);
        Route::delete('me/signature', [\App\Http\Controllers\Api\MySignatureController::class, 'destroy']);
        Route::patch('me', [ProfileController::class, 'update']);
        // Own schedule, only when the organization's self_schedule switch is on.
        Route::patch('me/schedule', [ProfileController::class, 'updateSchedule']);

        // Pictures are served through a controller, never as a public file. A
        // guessable avatar URL leaks who works where, across every tenant.
        Route::post('me/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('me/avatar', [ProfileController::class, 'destroyAvatar']);
        Route::get('users/{user}/avatar', [ProfileController::class, 'avatar']);
        Route::get('me/sessions', [ProfileController::class, 'sessions']);

        // API keys and the MCP endpoint: an AI acting as this person (EnforceApiKeyAccess).
        Route::get('audit-events/{id}', [\App\Http\Controllers\Api\AuditEventsController::class, 'show'])
            ->middleware('permission:audit.view')->whereUuid('id');
        Route::get('audit-events', [\App\Http\Controllers\Api\AuditEventsController::class, 'index'])
            ->middleware('permission:audit.view');
        Route::get('me/api-keys', [\App\Http\Controllers\Api\ApiKeysController::class, 'index']);
        Route::post('me/api-keys', [\App\Http\Controllers\Api\ApiKeysController::class, 'store']);
        Route::delete('me/api-keys/{id}', [\App\Http\Controllers\Api\ApiKeysController::class, 'destroy'])->whereNumber('id');
        Route::get('api-keys/activity', [\App\Http\Controllers\Api\ApiKeysController::class, 'activity']);
        Route::post('api-keys/activity/{event}/revert', [\App\Http\Controllers\Api\ApiKeysController::class, 'revert']);
        Route::post('mcp', [\App\Http\Controllers\Api\McpController::class, 'handle'])->middleware('throttle:120,1');

        // ── Deleting your own account ────────────────────────────
        //
        // No policy and no id in the path, for the same reason as the routes
        // above: the subject is read off the token, so there is nobody else to
        // authorize against and no way to aim this at a colleague.
        //
        // The GET is not decoration. A purge is irreversible and its report is
        // the only place anybody learns what does NOT go - the same argument
        // PurgePersonalData makes for its mandatory dry run, and it applies
        // twice over when the person erasing themselves has to live with it.
        Route::get('me/deletion', [AccountDeletionController::class, 'show']);
        Route::delete('me', [AccountDeletionController::class, 'destroy']);
        Route::delete('me/sessions', [ProfileController::class, 'revokeOtherSessions']);

        // ── Your own preferences (§2.1 category 6) ───────────────
        //
        // Kept apart from /settings on purpose. Those are the organization's,
        // set by an administrator; these are the person's. §2.1 is explicit that
        // the categories must not be merged into one toggle system.
        Route::get('me/preferences', [PreferencesController::class, 'index']);
        Route::patch('me/preferences', [PreferencesController::class, 'update']);

        // Each tab's how-to video (UI queue 81): platform-wide, kept by staff,
        // the same for everybody signed in.
        Route::get('help/videos', [\App\Http\Controllers\Api\HelpVideosController::class, 'index']);

        // ── Your own second factor (§6) ──────────────────────────
        //
        // Self-service on purpose: anybody signed in can switch it on without an
        // administrator, and without anyone from EzBizi being available. No
        // policy — every route is bound to the signed-in user's own record.
        Route::get('me/totp', [TwoFactorController::class, 'show']);
        Route::post('me/totp', [TwoFactorController::class, 'begin']);
        Route::post('me/totp/confirm', [TwoFactorController::class, 'confirm']);
        Route::delete('me/totp', [TwoFactorController::class, 'disable']);

        // EzBizi texting the person, from EzBizi's own number - not any
        // tenant's line. Beside the authenticator because it is the same
        // question ("how do we know it is you"), and under /me for the same
        // reason: RequireTwoFactorEnrolment exempts /me, so somebody who has
        // not enrolled can still reach the thing that lets them.
        Route::get('me/sms-codes', [SmsCodesController::class, 'show']);
        Route::post('me/sms-codes', [SmsCodesController::class, 'begin']);
        Route::post('me/sms-codes/confirm', [SmsCodesController::class, 'confirm']);
        Route::delete('me/sms-codes', [SmsCodesController::class, 'disable']);

        // ── Notifications (§12) ──────────────────────────────────
        //
        // No policy: every query is bound to the signed-in user's own id, so
        // there is nothing to authorize beyond being signed in.
        Route::get('notifications', [NotificationsController::class, 'index']);
        Route::post('notifications/devices', [NotificationsController::class, 'registerDevice']);
        Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationsController::class, 'markRead']);

        // Alerts: one person to chosen colleagues (Communication ▸ Alerts).
        // Gated in the controller on phone.alerts.view, the tab's own key.
        Route::get('alerts/recipients', [\App\Http\Controllers\Api\AlertsController::class, 'recipients']);
        Route::get('alerts', [\App\Http\Controllers\Api\AlertsController::class, 'index']);
        Route::post('alerts', [\App\Http\Controllers\Api\AlertsController::class, 'send']);

        // ── Settings (§6) ────────────────────────────────────────
        Route::get('settings', [SettingsController::class, 'index']);
        Route::patch('settings/{key}', [SettingsController::class, 'update']);
        Route::delete('settings/{key}', [SettingsController::class, 'destroy']);

        /*
        | The mail server a business sends its OWN mail through.
        |
        | Singular and scope-addressed, with NO {identity} segment: there is at
        | most one per scope, enforced by a unique key, so there is no id for a
        | caller to guess and no route binding that could resolve one belonging
        | to a scope they cannot reach. The scope arrives as query parameters the
        | same way /settings takes it, because it is the same cascade.
        |
        | PUT rather than PATCH because the body is the whole connection — five
        | fields that only make sense together. A partial update of an SMTP
        | connection is a connection that cannot be dialled.
        |
        | This is NOT the sending-identity screen Jose deleted on 2026-09-14.
        | That one asked a business to prove a domain so EzBizi's SES would sign
        | as them; this asks for their own server, with our SES not in the path.
        */
        Route::get('mail/sending-server', [SendingServersController::class, 'show']);
        Route::put('mail/sending-server', [SendingServersController::class, 'connect']);
        Route::delete('mail/sending-server', [SendingServersController::class, 'disconnect']);

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
                    'onboarding' => ($open = \App\Models\Onboarding::query()
                        ->where('user_id', $user->id)
                        ->whereIn('status', \App\Models\Onboarding::OPEN)
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
                        ->map(fn ($c) => [
                            'id' => $c->id,
                            'name' => $c->name,
                            'parent_company_id' => $c->parent_company_id,
                            'has_logo' => $c->logo_path !== null,
                            // Switched off: kept in reach, not offered by the
                            // view switcher (Jose, 2026-10-15).
                            'is_active' => (bool) $c->is_active,
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
 * Monitoring heartbeat — a person reports their own presence. Deliberately
 * OUTSIDE `idle`: SlideIdleWindow pushes the token's expiry forward on every
 * authenticated call, so a timer-driven beat would keep a walked-away session
 * alive forever and silently void security.session_timeout_minutes. Presence is
 * not user activity for the idle clock; genuine navigation (which hits the
 * idle-bearing group above) is. No `subscription` or `two-factor` either — a
 * background beat should not be interrupted by either gate.
 */
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant'])
    ->group(function () {
        Route::post('monitoring/heartbeat', [MonitoringHeartbeatController::class, 'store'])
            ->middleware('throttle:120,1');

        // A device's own periodic screen capture — same idle-less reasoning
        // as the heartbeat above (see ScreenshotsController's docblock).
        Route::post('monitoring/screenshots', [MonitoringScreenshotsController::class, 'store'])
            ->middleware('throttle:60,1');

        // Live View's agent half — "is anyone watching me" and "here is my
        // latest frame", polled/sent every ~1-2s for as long as the agent
        // runs. Same idle-less reasoning as the heartbeat and the screenshot
        // upload just above: a background loop must never be what keeps a
        // walked-away session's idle window open. See LiveViewController's
        // docblock. Throttled generously above the ~1-2s send rate the
        // controller already assumes, the same margin ScreenshotsController
        // and HeartbeatController leave themselves.
        Route::get('monitoring/live-wanted', [MonitoringLiveViewController::class, 'liveWanted'])
            ->middleware('throttle:120,1');
        Route::post('monitoring/live-frame', [MonitoringLiveViewController::class, 'liveFrame'])
            ->middleware('throttle:120,1');

        // Remote Control's agent half — "what should I apply, and is
        // anyone still driving me" — same idle-less and throttle reasoning
        // as `live-wanted` just above. See RemoteControlController's
        // docblock.
        Route::get('monitoring/input-pending', [MonitoringRemoteControlController::class, 'inputPending'])
            ->middleware('throttle:120,1');

        // Remote file access's agent half — "what should I read" and "here is
        // the answer" — same idle-less and throttle reasoning as
        // `input-pending` just above. See FilesController.
        Route::get('monitoring/file-requests', [MonitoringFilesController::class, 'pending'])
            ->middleware('throttle:120,1');
        Route::post('monitoring/file-requests/{requestId}', [MonitoringFilesController::class, 'answer'])
            ->middleware('throttle:120,1');
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
        // What the apps reported going wrong, grouped by problem (2026-10-15).
        Route::get('health/client-issues', [StaffClientIssuesController::class, 'index']);

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

        // The suggestion queue. Reads across every tenant on purpose — control
        // plane, behind the `staff` middleware, not `tenant`.
        Route::get('suggestions', [StaffSuggestionsController::class, 'index']);
        Route::post('suggestions/{suggestion}/decide', [StaffSuggestionsController::class, 'decide']);
        Route::get('suggestions/{suggestion}/files/{index}', [StaffSuggestionsController::class, 'file'])->whereNumber('index');

        Route::get('client-versions', [StaffClientVersionsController::class, 'index']);
        Route::put('client-versions/{platform}', [StaffClientVersionsController::class, 'update']);

        // Which tab has which how-to video (UI queue 81). Platform-wide like the
        // client versions: a new video is a save, not a release.
        Route::get('help-videos', [\App\Http\Controllers\Api\StaffHelpVideosController::class, 'index']);
        Route::put('help-videos/{module}', [\App\Http\Controllers\Api\StaffHelpVideosController::class, 'update']);
        Route::delete('help-videos/{module}', [\App\Http\Controllers\Api\StaffHelpVideosController::class, 'destroy']);

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
