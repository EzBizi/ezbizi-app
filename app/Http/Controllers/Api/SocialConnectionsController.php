<?php

namespace App\Http\Controllers\Api;

use App\Support\Workiz\WorkizV2;
use App\Console\Commands\BackfillWorkizJobs;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Integrations\DisconnectCode;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\TikTokPublisher;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
use App\Support\Social\SocialProviderRegistry;
use App\Support\Social\ThumbtackAccount;
use App\Support\Social\WhatsAppHealth;
use App\Support\Telegram\TelegramBotStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Status;

class SocialConnectionsController extends Controller
{
    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Marketing')->message(),
        );

        /*
         * Places the tenant publishes to or sends through - never the data
         * sources. Google Ads, Business Profile and Thumbtack are read by
         * Leads, Reviews and Business Profile, each through its own payload,
         * and nothing can be posted to one. Listing them here put twenty-two
         * Google Ads accounts in the Social Media Management composer as
         * "Pages", each with a tick box, as if a post could go to one.
         *
         * ── EXCEPT WHEN THE CALLER ASKS FOR THEM ────────────────────
         *
         * Jose, 2026-09-16, wants Airtable and Workiz connectable from Settings
         * > Integrations rather than from the one screen that reads them. That
         * screen and the composer share this endpoint, so the choice was to
         * drop the filter for both or to let a caller opt in.
         *
         * Opt-in, because dropping it is not neutral: `can_publish` already
         * tells the composer which rows it may offer, so a data source would
         * render there DISABLED rather than as a page - correct, and still
         * fifty-three Google Ads rows of clutter in a composer that has nothing
         * to do with them. The default stays exactly as it was; Integrations
         * passes `include=data_sources` and nothing else changes.
         */
        $includeDataSources = $request->query('include') === 'data_sources';

        $rows = SocialConnection::query()
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c)
                && ($includeDataSources
                    || ! in_array($c->providerEnum(), SocialProvider::dataSources(), true)))
            ->values();

        // A Telegram bot whose webhook Telegram cannot deliver to "needs
        // attention", with Telegram's own last error as the reason.
        $telegramError = $rows
            ->filter(fn (SocialConnection $c) => $c->provider === SocialProvider::Telegram->value && $c->status === SocialConnection::STATUS_CONNECTED)
            ->mapWithKeys(fn (SocialConnection $c) => [$c->getKey() => TelegramBotStatus::webhookError($c)]);
        $whatsappPurpose = WhatsAppNumber::query()->whereIn('social_connection_id', $rows->modelKeys())
            ->pluck('purpose', 'social_connection_id');

        return response()->json([
            'data' => $rows->map(fn (SocialConnection $c) => [
                'id' => $c->getKey(),
                'provider' => $c->provider,
                'provider_label' => $c->providerLabel(),
                'display_name' => $c->display_name,

                /*
                 * The tenant's own name for it, and NOT folded into
                 * display_name. A screen needs both: the label is what a person
                 * recognises, the display name is what the provider calls it,
                 * and an Airtable base's display name is its id - so collapsing
                 * them here would leave a renamed connection with no way to
                 * show which base it actually is.
                 *
                 * `rename` has been able to set this since before I arrived;
                 * nothing listed it, so naming a connection had no visible
                 * effect anywhere. A write with no read.
                 */
                'label' => $c->label,

                'handle' => $c->handle,
                // The Google login it was signed in with; null = unknown, reconnect to see (Jose, 2026-11-02).
                'account_email' => $c->account_email,
                'scope_type' => $c->scope_type,
                'scope_id' => $c->scope_id,
                'status' => ($telegramError[$c->getKey()] ?? null) !== null ? SocialConnection::STATUS_BROKEN : $c->status,
                'status_reason' => $telegramError[$c->getKey()] ?? $c->status_reason,
                // Which Workiz API the key speaks, and how far its history import
                // has got (Jose, 2026-10-19: the card said neither).
                'detail' => match ($c->provider) {
                    SocialProvider::Workiz->value => self::workizDetail($c),
                    SocialProvider::Telegram->value => TelegramBotStatus::detail($c),
                    // Meta refused the lead-ad subscription; Messenger still arrives (SubscribeMetaMessages).
                    SocialProvider::FacebookPage->value => isset($c->credentials['leadgen_refused'])
                        ? 'Lead ads: off — '.$c->credentials['leadgen_refused'] : null,
                    default => null,
                },
                // The same, as fields for the Workiz card's own controls.
                'workiz' => $c->provider === SocialProvider::Workiz->value ? [
                    'method' => WorkizV2::uses($c) ? 'v2' : 'v1',
                    'last_synced_at' => \App\Models\WorkizJob::query()->where('franchise_id', $c->scope_id)->max('synced_at'),
                    'history' => BackfillWorkizJobs::state($c),
                    'health' => \App\Support\Workiz\WorkizHealth::check($c),
                ] : null,
                // What the number is for; its card asks right after connecting
                // (PATCH .../whatsapp-purpose) and shows .../whatsapp-health.
                'whatsapp' => $c->provider === SocialProvider::WhatsApp->value ? [
                    'purpose' => $whatsappPurpose[$c->getKey()] ?? null,
                ] : null,
                'last_reached_at' => $c->last_reached_at?->toIso8601String(),
                // When ezbizi:check-connection-health last had a verdict (Jose, 2026-11-03).
                'last_checked_at' => $c->last_checked_at?->toIso8601String(),

                /*
                 * Connected is not the same as postable: four of the nine
                 * networks a tenant can connect have no publisher yet. §4 says a
                 * control that is offered must work, so the composer needs to
                 * know which accounts it may offer — and it must not decide that
                 * from the provider key, which would be a second copy of the
                 * publisher map that goes stale the day one is added.
                 */
                'can_publish' => Publishers::refusalFor($c->providerEnum()) === null,
                'cannot_publish_because' => Publishers::refusalFor($c->providerEnum()),

                /*
                 * What posting here actually does, when it is not what somebody
                 * would assume. Null for every network that holds no surprise,
                 * so the screen draws nothing rather than a reassurance.
                 */
                'posting_note' => Publishers::postingNoteFor($c->providerEnum()),
                // TikTok Direct Post: the composer must ask privacy, interactions
                // and disclosure for this account (GET .../tiktok-creator).
                'asks_tiktok_choices' => $c->providerEnum() === SocialProvider::TikTok && Publishers::tiktokIsAudited(),
                // The composer's limit and pre-flight caution come from the
                // same rules that refuse at publish (review 2026-11-09 C4).
                // Only for a network that posts: Mailchimp, Workiz and the rest
                // are connections with nothing to refuse.
                'body_limit' => $c->providerEnum()?->maxBodyLength(),
                'refuses' => Publishers::refusalFor($c->providerEnum()) === null
                    ? collect(SocialMediaKind::cases())
                        ->mapWithKeys(fn (SocialMediaKind $k) => [$k->value => $c->providerEnum()?->refuses($k)])
                        ->all()
                    : (object) [],
            ])->all(),
        ]);
    }

    /**
     * PATCH /api/v1/social/connections/{connection}/label
     *
     * Name an account, because nobody remembers what the numbers mean.
     *
     * `display_name` is Google's — `405-172-9356` — and stays untouched: it is
     * how somebody cross-checks a row against the advertising account behind
     * it. This is the name a person chose, stored beside it. Twenty-two
     * numbered rows is the actual complaint the Leads accounts list exists to
     * answer, and a count tells them apart only while their counts differ.
     *
     * Blank input clears the name rather than storing an empty string, so
     * "unnamed" has exactly one representation and the screen never has to
     * decide whether '' means anything.
     */
    /**
     * Who a TikTok account is and what it allows, for the composer's
     * Direct Post panel: nickname, privacy levels to offer, which of
     * comment/duet/stitch the account has switched off, and the longest
     * video it may post. TikTok's guidelines require all of it on screen.
     */
    public function tiktokCreator(Request $request, string $connection): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('publish to a social account')->message(),
        );

        $row = SocialConnection::query()->find($connection);

        abort_if(
            $row === null || ! $this->access->inView($user, $row) || $row->providerEnum() !== SocialProvider::TikTok,
            Status::HTTP_NOT_FOUND,
        );

        $publisher = new TikTokPublisher;

        try {
            $info = $publisher->creatorInfo($publisher->token($row));
        } catch (SocialPublishFailed $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => [
            'nickname' => (string) ($info['creator_nickname'] ?? $row->display_name),
            'username' => $info['creator_username'] ?? null,
            'avatar_url' => $info['creator_avatar_url'] ?? null,
            'privacy_level_options' => array_values((array) ($info['privacy_level_options'] ?? [])),
            'comment_disabled' => (bool) ($info['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($info['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($info['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => isset($info['max_video_post_duration_sec']) ? (int) $info['max_video_post_duration_sec'] : null,
        ]]);
    }

    public function rename(Request $request, string $connection): JsonResponse
    {
        $user = $request->user();

        $row = SocialConnection::query()->find($connection);

        abort_if(
            $row === null || ! $this->access->inView($user, $row),
            Status::HTTP_NOT_FOUND,
        );

        // The same gate as connecting, disconnecting and placing: the module
        // that reads the account decides what it is called.
        $provider = SocialProvider::tryFrom($row->provider);

        abort_unless(
            $user->hasPermission($provider?->connectPermission() ?? 'marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('rename a connected account')->message(),
        );

        $input = $request->validate([
            'label' => ['present', 'nullable', 'string', 'max:80'],
        ]);

        $label = trim((string) ($input['label'] ?? '')) ?: null;
        $before = $row->label;

        if ($before === $label) {
            return response()->json(['data' => $this->placement($row)]);
        }

        DB::transaction(function () use ($row, $label, $before) {
            $row->forceFill(['label' => $label])->save();

            $this->audit->log('social.renamed', $row, [
                'provider' => $row->provider,
                'before' => $before,
                'after' => $label,
            ]);
        });

        return response()->json(['data' => $this->placement($row->fresh())]);
    }

    /**
     * PATCH /api/v1/social/connections/{connection}/whatsapp-purpose
     *
     * What a connected WhatsApp number is for, asked right after connecting
     * (Jose, 2026-11-12). Reports and sign-in codes go from ONE number per
     * organization, so choosing either here moves it: the number that had it
     * goes back to customer chat rather than leaving forPurpose() to pick.
     */
    public function whatsappPurpose(Request $request, string $connection): JsonResponse
    {
        [$row, $number] = $this->whatsappNumber($request, $connection);

        $purpose = $request->validate([
            'purpose' => ['required', Rule::in(WhatsAppNumber::PURPOSES)],
        ])['purpose'];
        $before = $number->purpose;

        DB::transaction(function () use ($row, $number, $purpose, $before) {
            $moved = in_array($purpose, WhatsAppNumber::ONE_PER_ORGANIZATION, true)
                ? WhatsAppNumber::query()->where('purpose', $purpose)->whereKeyNot($number->getKey())->get()
                : collect();

            $moved->each(fn (WhatsAppNumber $other) => $other->forceFill(['purpose' => 'company'])->save());
            $number->forceFill(['purpose' => $purpose])->save();

            $this->audit->log('whatsapp.purpose_changed', $row, [
                'before' => $before,
                'after' => $purpose,
                'moved_from' => $moved->pluck('display_number')->all(),
            ]);
        });

        return response()->json(['data' => ['purpose' => $purpose]]);
    }

    /**
     * GET /api/v1/social/connections/{connection}/whatsapp-health
     *
     * Pass or fail per line, with what to do, fixing what EzBizi can on the
     * way (WhatsAppHealth). The card calls it when it is drawn.
     */
    public function whatsappHealth(Request $request, string $connection): JsonResponse
    {
        [$row, $number] = $this->whatsappNumber($request, $connection);

        return response()->json(['data' => WhatsAppHealth::check($row, $number)]);
    }

    /** @return array{0: SocialConnection, 1: WhatsAppNumber} The same gate as renaming it. */
    private function whatsappNumber(Request $request, string $connection): array
    {
        $user = $request->user();
        $row = SocialConnection::query()->find($connection);
        $number = $row === null ? null : WhatsAppNumber::query()->where('social_connection_id', $row->getKey())->first();

        abort_if($number === null || ! $this->access->inView($user, $row), Status::HTTP_NOT_FOUND);
        abort_unless(
            $user->hasPermission(SocialProvider::WhatsApp->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('change a connected WhatsApp number')->message(),
        );

        return [$row, $number];
    }

    /**
     * PATCH /api/v1/social/connections/{connection}/leads-franchise
     *
     * Which franchise owns the enquiries this account produces.
     *
     * Separate from `scope_id`, which says who can SEE and ACT ON the
     * connection. Null puts it back at company level, which is where every
     * account starts.
     *
     * This used to add "because inView() makes a franchise-scoped connection
     * invisible to the company above". That was measured on 2026-09-14 and is
     * false — inView()'s COMPANY branch admits a franchise-scoped connection
     * whose franchise belongs to the viewer's company, and has since d3f17b70
     * on 2026-08-21. The migration carries the full correction; this was the
     * third copy of the same wrong sentence.
     */
    /**
     * PATCH /api/v1/social/connections/{connection}/business-location
     *
     * Which Business Profile listing a Local Services account serves from - the
     * office address (A1 Leads takeover, 2026-10-19). A person's choice is
     * final; null hands it back to the automatic link. Same gate as placing
     * the account's leads.
     */
    public function linkBusinessLocation(Request $request, string $connection): JsonResponse
    {
        $user = $request->user();
        $row = SocialConnection::query()->find($connection);
        abort_if($row === null || ! $this->access->inView($user, $row), Status::HTTP_NOT_FOUND);
        abort_unless(
            $user->hasPermission(SocialProvider::tryFrom($row->provider)?->connectPermission() ?? 'marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('link an account to a Business Profile')->message(),
        );
        $input = $request->validate(['business_location_id' => ['present', 'nullable', 'uuid']]);

        $location = null;
        if ($input['business_location_id'] !== null) {
            // Whose: a valid id from another organization must not link (as placeLeads).
            $location = \App\Models\BusinessLocation::query()->whereKey($input['business_location_id'])
                ->where('organization_id', $row->organization_id)->first();
            abort_if($location === null, Status::HTTP_NOT_FOUND);
        }
        app(\App\Support\Leads\AccountProfileLink::class)->link($row, $location, $user);

        return response()->json(['data' => [
            'business_location_id' => $row->business_location_id,
            'office' => $location === null ? null : trim(implode(', ', array_filter([$location->address_line1, $location->city, $location->state]))),
        ]]);
    }

    public function placeLeads(Request $request, string $connection): JsonResponse
    {
        $user = $request->user();

        $row = SocialConnection::query()->find($connection);

        abort_if(
            $row === null || ! $this->access->inView($user, $row),
            Status::HTTP_NOT_FOUND,
        );

        // Same gate as connecting and disconnecting: the module that reads the
        // account decides where its leads go.
        $provider = SocialProvider::tryFrom($row->provider);

        abort_unless(
            $user->hasPermission($provider?->connectPermission() ?? 'marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('place a connected account')->message(),
        );

        $input = $request->validate([
            'leads_franchise_id' => ['present', 'nullable', 'uuid'],
        ]);

        $franchiseId = $input['leads_franchise_id'] ?? null;

        if ($franchiseId !== null) {
            /*
             * The franchise must be in THIS connection's organization.
             *
             * A foreign key only proves the row exists; it says nothing about
             * whose it is. Without this, a valid-looking id belonging to
             * another tenant would route one customer's enquiries into
             * another customer's list — a cross-tenant write dressed as a
             * dropdown choice.
             */
            $owned = Franchise::query()
                ->whereKey($franchiseId)
                ->where('organization_id', $row->organization_id)
                ->exists();

            abort_unless($owned, Status::HTTP_NOT_FOUND);
        }

        $before = $row->leads_franchise_id;

        if ($before === $franchiseId) {
            return response()->json(['data' => $this->placement($row)]);
        }

        $row->forceFill(['leads_franchise_id' => $franchiseId])->save();

        /*
         * Retroactive, on Jose's ruling of 2026-09-08: assigning an account
         * moves the leads it has ALREADY produced, not only the next ones. A
         * manager setting this up expects the branch to see its own history
         * rather than an empty list.
         *
         * The cost is real and was accepted with it stated: a lead's
         * franchise_id stops recording where it arrived and starts reflecting
         * the account's current assignment, so re-assigning later moves the
         * history again.
         *
         * Chunked rather than one UPDATE because 400 leads today is not 400
         * forever, and an unbounded write on a growing table is a lock somebody
         * eventually notices. One audit row at the end, not one per chunk: the
         * event is the re-assignment, not the batching.
         */
        $moved = 0;

        Lead::query()
            ->where('connection_id', $row->getKey())
            ->select('id')
            ->chunkById(500, function ($leads) use ($franchiseId, &$moved) {
                DB::transaction(function () use ($leads, $franchiseId, &$moved) {
                    $moved += Lead::query()
                        ->whereIn('id', $leads->pluck('id'))
                        ->update(['franchise_id' => $franchiseId]);
                });
            });

        $this->audit->log('social.leads_placed', $row, [
            'provider' => $row->provider,
            'display_name' => $row->display_name,
            'before' => $before,
            'after' => $franchiseId,
            'leads_moved' => $moved,
        ], actor: $user);

        /*
         * The moved count goes back with the response.
         *
         * Placement is retroactive, so the thing a person looks for to believe
         * it worked is "14 leads moved to Connecticut" - not the row quietly
         * changing. Returning it here saves the screen a full refetch to
         * discover a number the write already knew.
         */
        return response()->json(['data' => $this->placement($row->fresh(), $moved)]);
    }

    /** @return array<string, mixed> */
    private function placement(SocialConnection $row, ?int $moved = null): array
    {
        return [
            'id' => $row->getKey(),
            'provider' => $row->provider,
            'display_name' => $row->display_name,

            // The chosen name, null when nobody has set one. Kept separate
            // from display_name so the screen can show one and cross-check
            // against the other.
            'label' => $row->label,
            'leads_franchise_id' => $row->leads_franchise_id,

            // Null when nothing changed, 0 when a placement moved no leads.
            // Those are different answers and the screen says different things.
            'leads_moved' => $moved,
        ];
    }

    /**
     * Forget a connection.
     *
     * The row goes, tokens and all. It does NOT revoke the app at the network's
     * end — only the network's own settings can do that, which is what the
     * privacy policy tells tenants. Reconnecting makes a fresh row.
     */
    public function destroy(Request $request, string $connection): JsonResponse
    {
        $user = $request->user();

        $row = SocialConnection::query()->find($connection);

        abort_if(
            $row === null || ! $this->access->inView($user, $row),
            Status::HTTP_NOT_FOUND,
        );

        $this->mayDisconnect($user, $row);

        // A fresh authenticator code, after the row is found and authorised.
        app(DisconnectCode::class)->require($request);

        $problems = $this->letGo($user, $row);

        return response()->json(['data' => [
            'id' => $connection,
            // Additive. The disconnection succeeded either way; this says
            // whether anything is left behind on Thumbtack for a person to
            // remove there.
            'problems' => $problems,
        ]]);
    }

    /**
     * Every connection of one provider in view, gone in one action.
     *
     * One Thumbtack consent makes a row per business — twelve for A-1 Chimney
     * on 2026-09-09 — and they share one grant. Disconnecting them one at a
     * time is twelve confirmations, and the first one revokes the grant the
     * other eleven still need for their own tidy-up. Jose, same day: "we
     * should have a disconnect all instead, so it disconnected the entire
     * thumbtack account". So: numbers and webhook per business, the grant
     * revoked once, on the last row.
     */
    public function destroyProvider(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        $provider = SocialProvider::tryFrom($provider);

        abort_if($provider === null, Status::HTTP_NOT_FOUND);

        // One product of the sign-in (design 183), or everything it brought.
        $product = $request->filled('product')
            ? (collect(app(SocialProviderRegistry::class)->products($provider))->firstWhere('key', $request->input('product'))
                ?? abort(Status::HTTP_NOT_FOUND))
            : null;

        $rows = SocialConnection::query()
            // Everything the one sign-in brought: Facebook takes its ad accounts with it.
            ->whereIn('provider', $product === null
                ? array_map(fn (SocialProvider $p) => $p->value, $provider->family())
                : [$product['provider']])
            // One Google login's rows only (design 167): a string is that
            // login, null is the rows whose login is unknown.
            ->when($request->has('account_email'), fn ($q) => $q->where('account_email', $request->input('account_email')))
            ->orderBy('created_at')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        if ($rows->isNotEmpty()) {
            $rows->unique('provider')->each(fn (SocialConnection $row) => $this->mayDisconnect($user, $row));

            app(DisconnectCode::class)->require($request);
        }

        // Messenger and Instagram comments ride on the Page rows: they go by
        // taking their permissions back, and the Pages stay.
        if (isset(FacebookOAuth::PRODUCTS[$product['key'] ?? '']) && $product['provider'] === SocialProvider::FacebookPage->value
            && $product['key'] !== 'pages') {
            return $this->withdraw($user, $rows, $product['key']);
        }

        $problems = [];

        if (isset(FacebookOAuth::PRODUCTS[$product['key'] ?? ''])) {
            $problems = $this->revokeMeta($rows, $product['key']);
        }

        foreach ($rows as $i => $row) {
            $problems = [...$problems, ...$this->letGo($user, $row, revoke: $i === $rows->count() - 1)];
        }

        return response()->json(['data' => [
            'provider' => $provider->value,
            'disconnected' => $rows->count(),
            'problems' => array_values(array_unique($problems)),
        ]]);
    }

    /**
     * The scopes only this Facebook product uses, taken back from Meta with
     * the person's token (an ad account row holds it; a Page row holds the
     * Page's). Never fatal: what Meta would not take back is said, and the
     * rows go regardless.
     *
     * @return string[]
     */
    private function revokeMeta(Collection $rows, string $key): array
    {
        $others = array_merge(...array_values(array_map(fn ($p) => $p['scopes'], array_diff_key(FacebookOAuth::PRODUCTS, [$key => 1]))));
        $own = array_values(array_diff(FacebookOAuth::PRODUCTS[$key]['scopes'], $others));
        $token = SocialConnection::query()->where('provider', SocialProvider::FacebookAds->value)
            ->whereIn('organization_id', $rows->pluck('organization_id')->unique())
            ->get()->map(fn (SocialConnection $c) => $c->credentials['access_token'] ?? null)->filter()->first();

        if (! is_string($token)) {
            return ['Facebook still lists this access for EzBizi. Remove it in Facebook > Settings > Business integrations.'];
        }

        $failed = array_filter($own, fn (string $scope) => ! Http::timeout(10)->delete(
            'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/me/permissions/'.$scope, ['access_token' => $token],
        )->successful());

        return $failed === [] ? [] : ['Facebook would not take back '.implode(', ', $failed).'. Remove it in Facebook > Settings > Business integrations.'];
    }

    /** A product that has no rows of its own: its permissions back, its Pages kept. */
    private function withdraw(User $user, Collection $rows, string $key): JsonResponse
    {
        $problems = $this->revokeMeta($rows, $key);
        $mark = FacebookOAuth::MARKS[$key];

        foreach ($rows as $row) {
            // Recorded as withdrawn, so Integrations stops saying it is there.
            $scopes = (array) ($row->credentials['scopes'] ?? FacebookOAuth::SCOPES) ?: FacebookOAuth::SCOPES;
            $row->forceFill(['credentials' => [...$row->credentials, 'scopes' => array_values(array_diff($scopes, [$mark]))]])->save();
        }

        $this->audit->log('social.product_withdrawn', $rows->first() ?? $user->organization, [
            'product' => $key,
            'pages' => $rows->count(),
            'not_tidied' => $problems === [] ? null : $problems,
        ], actor: $user);

        return response()->json(['data' => [
            'provider' => SocialProvider::FacebookPage->value,
            'product' => $key,
            'disconnected' => 0,
            'problems' => $problems,
        ]]);
    }

    /**
     * The row is found FIRST because the permission depends on which
     * provider it is, and this used to be one blanket `marketing.edit` for
     * everything the controller can delete.
     *
     * That was half a fix left behind. connectPermission() moved the two
     * Google providers to the modules that actually read them — Google Ads
     * to Leads, Business Profile to Reviews — but disconnecting stayed with
     * Marketing, so the desk that could connect an account could not remove
     * it, and a desk with no access to Leads at all could. Jose connected
     * the wrong Google Ads account and had to go to a module he was not
     * working in to undo it.
     *
     * Asking the provider keeps the two ends symmetrical by construction:
     * a provider added later is gated the same way at both, and the nine
     * publishing networks are untouched because theirs already answers
     * `marketing.edit`.
     */
    private function mayDisconnect(User $user, SocialConnection $row): void
    {
        $provider = SocialProvider::tryFrom($row->provider);

        abort_unless(
            $user->hasPermission($provider?->connectPermission() ?? 'marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('disconnect a social account')->message(),
        );
    }

    /**
     * Thumbtack is told, before the row goes.
     *
     * Deleting our own row is the easy half. Until 2026-09-08 it was the
     * only half: the webhook stayed registered and firing at an endpoint
     * that no longer recognised it, the associate phone numbers stayed on
     * Thumbtack still claiming that a departed company's handsets answer
     * for that business, and the grant stayed listed on the Pro's Thumbtack
     * account as an app with access.
     *
     * The Help Center article Thumbtack published for EzBizi states plainly
     * that disconnecting does all three. `ThumbtackAccount::tidyUp()` was
     * written to do it and nothing called it — it had tests, and tests on a
     * method say nothing about whether anything reaches it.
     *
     * INLINE, and it has to be: queued, this would run after the row is
     * deleted, and the tokens it needs go with the row. The alternative is
     * copying credentials into a job payload, which is worse.
     *
     * Never fatal. tidyUp() reports rather than throws, because the account
     * most likely to fail here — an expired token, a business removed on
     * Thumbtack's side — is exactly the one somebody is trying to be rid
     * of. Refusing to let go because the far end will not answer would trap
     * them in a broken connection with no way out but the database.
     *
     * @return string[] what could not be undone on the far end
     */
    private function letGo(User $user, SocialConnection $row, bool $revoke = true): array
    {
        $problems = SocialProvider::tryFrom($row->provider) === SocialProvider::Thumbtack
            ? ThumbtackAccount::tidyUp($row, revoke: $revoke)
            : [];

        // A Telegram bot stops calling EzBizi (best effort). Its staff links
        // stay: reconnecting the same bot brings them straight back.
        if ($row->provider === SocialProvider::Telegram->value) {
            TelegramBotStatus::deleteWebhook($row);
        }

        $this->audit->log('social.disconnected', $row, [
            'provider' => $row->provider,
            'display_name' => $row->display_name,
            'scope_type' => $row->scope_type,
            // What could NOT be undone, on the record. A tidy-up that half
            // worked is otherwise invisible, and the half that failed is the
            // half somebody has to finish by hand on Thumbtack.
            'not_tidied' => $problems === [] ? null : $problems,
        ], actor: $user);

        // A business's own WhatsApp number stops routing and sending with its
        // connection: the row is what the webhook and every send resolve, and
        // left behind it would send with the global token on a number the
        // business has taken back. EzBizi's own numbers have no connection.
        WhatsAppNumber::query()->withoutGlobalScopes()
            ->where('social_connection_id', $row->getKey())
            ->delete();

        $row->delete();

        return $problems;
    }
    private static function workizDetail(SocialConnection $c): string
    {
        $history = BackfillWorkizJobs::state($c);

        return (WorkizV2::uses($c) ? 'API v2' : 'API v1').match (true) {
            $history === null => '',
            $history['done'] => ' · history imported',
            default => ' · importing history ('.number_format($history['jobs']).' jobs read)',
        };
    }
}
