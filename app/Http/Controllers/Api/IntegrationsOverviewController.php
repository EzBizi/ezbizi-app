<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Phone\NumbersController;
use App\Models\Automation;
use App\Models\Franchise;
use App\Models\GoogleWorkspaceConnection;
use App\Models\NewsletterAudience;
use App\Models\PhoneNumber;
use App\Models\SendingIdentity;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Support\Access\Refusal;
use App\Support\Mail\SendingState;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Scoping\ViewScope;
use App\Support\Social\ConnectionChecks;
use App\Support\Social\GoogleToken;
use App\Support\Social\Liveness\ConnectionLiveness;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * GET /api/v1/integrations/overview — one payload for Settings > Integrations
 * (redesign agreed with UI-UX 2026-11-12, options A and C of
 * docs/previews/integrations-patterns.html).
 *
 * Never calls a provider: every check is read from what the daily health run
 * and the setup read-back recorded (social_connections.checks, ConnectionChecks)
 * or from state already stored (phone health, sending server, Workspace).
 *
 * Status per connection: off (disabled / not ready, never attention) wins,
 * then broken (any check failed), then setup (a choice still needed, e.g. no
 * branch), then ok. summary counts connections, not apps.
 */
final class IntegrationsOverviewController extends Controller
{
    /** Providers whose leads are filed on a branch (leads_franchise_id). */
    private const LEAD_PRODUCERS = ['google_ads', 'thumbtack', 'google_business_profile', 'facebook_ads'];

    private const CHECK_LABELS = [
        'sign_in' => 'Sign-in',
        'messages' => 'Messages',
        'routing' => 'Message routing',
        'setup' => 'Setup',
        'branch' => 'Branch',
        'auto_reply' => 'Auto-reply',
        'routed' => 'Pointed at EzBizi',
        'answers' => 'Someone answers',
        'texting' => 'Texting (A2P)',
        'e911' => 'E911 address',
        'signed_in' => 'Someone signed in',
        'send' => 'Test send',
        'delegation' => 'Delegation',
    ];

    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly PhoneNumberAccess $phones,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Same gate as GET social/connections, which this screen replaces.
        abort_unless($user->hasPermission('marketing.view'), Status::HTTP_FORBIDDEN,
            Refusal::permission('open Integrations')->message());

        $branchNames = Franchise::query()->pluck('name', 'id');

        $rows = SocialConnection::query()->with('businessLocation')->orderBy('display_name')->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))->values();

        $usedBy = $this->usedBy($rows->map(fn (SocialConnection $c) => (string) $c->getKey()));
        $whatsApp = WhatsAppNumber::query()->get();
        $audiences = NewsletterAudience::query()->whereNotNull('mailchimp_list_id')->orderBy('name')->pluck('name');

        $connections = $rows->map(fn (SocialConnection $c) => $this->social($c, $branchNames, $usedBy, $whatsApp, $audiences));
        $connections = $connections
            ->concat($this->phoneLines($user))
            ->concat($this->sendingServers($user))
            ->concat($this->workspaces($user));

        $apps = $connections->groupBy('provider')->map(fn (Collection $group, string $provider) => [
            'provider' => $provider,
            'label' => self::appLabel($provider),
            'counts' => [
                'ok' => $group->where('status', 'ok')->count(),
                'setup' => $group->where('status', 'setup')->count(),
                'broken' => $group->where('status', 'broken')->count(),
            ],
            'last_checked_at' => $group->pluck('last_checked_at')->filter()->max(),
            'connections' => $group->values()->all(),
        ])->sortBy('label')->values();

        $view = ViewScope::forUser($user);

        return response()->json(['data' => [
            'summary' => [
                'broken' => $connections->where('status', 'broken')->count(),
                'setup' => $connections->where('status', 'setup')->count(),
            ],
            'apps' => $apps->all(),
            // Options for fix.action = choose_branch: the branches in view.
            // Saved with PATCH social/connections/{id}/leads-franchise.
            'branches' => $user->reachableFranchises()
                ->whereIn('id', $view->franchiseIds())
                ->sortBy('name')
                ->map(fn ($f) => ['id' => $f->id, 'label' => $f->name])
                ->values()->all(),
        ]]);
    }

    private function social(SocialConnection $c, Collection $branchNames, array $usedBy, Collection $whatsApp, Collection $audiences): array
    {
        $stored = ConnectionChecks::of($c);
        $checks = [];
        $paused = in_array($c->provider, [SocialProvider::YouTube->value, SocialProvider::GoogleBusinessProfile->value, SocialProvider::GoogleAds->value], true)
            && GoogleToken::paused($c);

        // Sign-in: the health run's verdict, else what the row says.
        $signIn = $stored['sign_in'] ?? null;
        if ($paused) {
            $checks[] = self::check('sign_in', ConnectionChecks::OFF, 'Paused in Settings.');
        } elseif ($c->status === SocialConnection::STATUS_BROKEN && ($signIn['state'] ?? null) !== ConnectionChecks::FAIL
            && (($stored['setup']['state'] ?? null) !== ConnectionChecks::FAIL)) {
            // Marked broken by a renewal or a publisher rather than the health run.
            $checks[] = self::check('sign_in', ConnectionChecks::FAIL, $c->status_reason ?: ConnectionLiveness::sentence($c->providerLabel()),
                self::fix('reconnect'), $c->last_checked_at?->toIso8601String(), ConnectionChecks::nextRun()->toIso8601String());
        } elseif ($signIn !== null) {
            $checks[] = self::stored('sign_in', $signIn);
        } elseif (! ConnectionLiveness::checks($c->provider)) {
            $checks[] = self::check('sign_in', ConnectionChecks::UNKNOWN, 'Not checked automatically for this app.');
        } else {
            $checks[] = self::check('sign_in', $c->last_checked_at ? ConnectionChecks::PASS : ConnectionChecks::UNKNOWN,
                $c->last_checked_at ? null : 'Not checked yet.', null, $c->last_checked_at?->toIso8601String(),
                ConnectionChecks::nextRun()->toIso8601String());
        }

        foreach (['messages', 'routing', 'setup'] as $key) {
            if (isset($stored[$key])) {
                $checks[] = self::stored($key, $stored[$key]);
            }
        }

        // Branch: lead producers must say which branch their leads are filed on.
        $branchId = $c->leads_franchise_id
            ?? ($c->scope_type === SocialConnection::SCOPE_FRANCHISE ? $c->scope_id : null);
        if (in_array($c->provider, self::LEAD_PRODUCERS, true)) {
            $checks[] = $branchId === null
                ? self::check('branch', ConnectionChecks::UNKNOWN, 'Choose which branch its leads go to.', self::fix('choose_branch'))
                : self::check('branch', ConnectionChecks::PASS);
        }

        $choices = [];
        if ($c->provider === SocialProvider::WhatsApp->value) {
            $number = $whatsApp->first(fn (WhatsAppNumber $n) => $n->social_connection_id === $c->getKey()
                || $n->phone_number_id === (string) $c->remote_id);
            if ($number?->purpose) {
                $choices[] = ['label' => 'Purpose', 'value' => ucfirst((string) $number->purpose)];
            }
            if ($number !== null) {
                // An option switched off by choice: grey, never attention.
                $checks[] = self::check('auto_reply', $number->auto_reply ? ConnectionChecks::PASS : ConnectionChecks::OFF,
                    $number->auto_reply ? null : 'Switched off.');
            }
        }
        if ($c->provider === SocialProvider::Mailchimp->value) {
            foreach ($audiences as $name) {
                $choices[] = ['label' => 'Audience', 'value' => (string) $name];
            }
        }
        if ($c->businessLocation !== null) {
            $choices[] = ['label' => 'Listing', 'value' => (string) $c->businessLocation->remote_name];
        }
        // ponytail: Pinterest's board is not stored (the publisher takes the first), so no choice yet.

        $off = $paused || $c->status === SocialConnection::STATUS_REVOKED;

        return self::connection([
            'id' => (string) $c->getKey(),
            'provider' => (string) $c->provider,
            'label' => (string) ($c->label ?: $c->display_name),
            'branch_id' => $branchId,
            'branch_label' => $branchId === null ? null : $branchNames->get($branchId),
            'choices' => $choices,
            'used_by' => $usedBy[(string) $c->getKey()] ?? 0,
        ], $checks, $off, $c->last_checked_at?->toIso8601String());
    }

    /** One row per phone line the person can see, checks from NumbersController::health. */
    private function phoneLines(User $user): Collection
    {
        if (! $user->can('viewAny', PhoneNumber::class)) {
            return collect();
        }

        return $this->phones->visibleTo($user)->map(function (PhoneNumber $n) {
            $health = NumbersController::health($n);
            $checks = collect($health['checks'])->map(fn (?string $problem, string $key) => self::check($key,
                // Nobody signed in right now is a moment, not a fault.
                $problem === null ? ConnectionChecks::PASS : ($key === 'signed_in' ? ConnectionChecks::UNKNOWN : ConnectionChecks::FAIL),
                $problem, null, now()->toIso8601String()))->values()->all();

            return self::connection([
                'id' => (string) $n->id,
                'provider' => 'phone',
                'label' => (string) ($n->label ?: $n->e164),
                'branch_id' => $n->scope_type === 'franchise' ? $n->scope_id : null,
                'branch_label' => null,
                'choices' => [],
                'used_by' => 0, // ponytail: phone dependents are counted per connection by the disconnect panel, not per line.
            ], $checks, false, now()->toIso8601String());
        })->values();
    }

    private function sendingServers(User $user): Collection
    {
        return SendingIdentity::query()->get()
            ->filter(fn (SendingIdentity $s) => $this->access->inView($user, self::scopeOf($s->scope_type, $s->scope_id)))
            ->map(function (SendingIdentity $s) {
                $state = $s->sendingState();
                $checked = $s->getAttribute('last_checked_at')?->toIso8601String();
                $check = match ($state) {
                    SendingState::Verified => self::check('send', ConnectionChecks::PASS, null, null, $checked),
                    SendingState::Failed => self::check('send', ConnectionChecks::FAIL,
                        $s->getAttribute('state_reason') ?: 'The mail server refused the last send.', self::fix('retry', 'Send a test'), $checked),
                    default => self::check('send', ConnectionChecks::UNKNOWN, 'Not checked yet: send a test from its panel.', null, $checked),
                };

                return self::connection([
                    'id' => (string) $s->getKey(),
                    'provider' => 'sending_server',
                    'label' => (string) $s->from_address,
                    'branch_id' => $s->scope_type === 'franchise' ? $s->scope_id : null,
                    'branch_label' => null,
                    'choices' => [],
                    'used_by' => 0,
                ], [$check], false, $checked);
            })->values();
    }

    private function workspaces(User $user): Collection
    {
        return GoogleWorkspaceConnection::query()->get()
            ->filter(fn (GoogleWorkspaceConnection $w) => $this->access->inView($user, self::scopeOf((string) $w->scope_type, (string) $w->scope_id)))
            ->map(function (GoogleWorkspaceConnection $w) {
                $check = match ($w->status) {
                    GoogleWorkspaceConnection::STATUS_CONNECTED => self::check('delegation', ConnectionChecks::PASS),
                    GoogleWorkspaceConnection::STATUS_BROKEN => self::check('delegation', ConnectionChecks::FAIL,
                        $w->status_reason ?: 'Google no longer accepts this Workspace connection.', self::fix('reconnect')),
                    default => self::check('delegation', ConnectionChecks::UNKNOWN, 'Waiting for domain-wide delegation in the Google Admin console.',
                        self::fix('open_url', 'Open Google Admin', 'https://admin.google.com/ac/owl/domainwidedelegation')),
                };

                return self::connection([
                    'id' => (string) $w->getKey(),
                    'provider' => 'google_workspace',
                    'label' => (string) $w->domain,
                    'branch_id' => $w->scope_type === 'franchise' ? $w->scope_id : null,
                    'branch_label' => null,
                    'choices' => [],
                    'used_by' => 0,
                ], [$check], $w->status === GoogleWorkspaceConnection::STATUS_PENDING, $w->updated_at?->toIso8601String());
            })->values();
    }

    /**
     * Live automations naming each connection's id in their saved steps, with
     * one query for all rows. ponytail: rules that need "some account of this
     * provider" (AutomationsCounter's lastOfProvider half) are not counted here;
     * the disconnect panel's GET integrations/{kind}/{id}/dependents has them.
     *
     * @return array<string, int>
     */
    private function usedBy(Collection $ids): array
    {
        $saved = Automation::query()->where('is_active', true)->whereNull('disabled_at')
            ->whereNotNull('active_version_id')->with('activeVersion')->get()
            ->map(fn (Automation $a) => (string) json_encode([$a->activeVersion?->actions ?? [], $a->activeVersion?->conditions ?? []]));

        return $ids->mapWithKeys(fn (string $id) => [$id => $saved->filter(fn (string $json) => str_contains($json, $id))->count()])->all();
    }

    private static function connection(array $base, array $checks, bool $off, ?string $lastChecked): array
    {
        $states = array_column($checks, 'state');
        $status = match (true) {
            $off => 'off',
            in_array(ConnectionChecks::FAIL, $states, true) => 'broken',
            collect($checks)->contains(fn (array $k) => ($k['fix']['action'] ?? null) === 'choose_branch') => 'setup',
            default => 'ok',
        };
        $lastChecked = collect($checks)->pluck('checked_at')->push($lastChecked)->filter()->max();

        return $base + ['status' => $status, 'last_checked_at' => $lastChecked, 'checks' => $checks];
    }

    private static function check(string $key, string $state, ?string $reason = null, ?array $fix = null, ?string $checkedAt = null, ?string $nextRetryAt = null): array
    {
        return [
            'key' => $key,
            'label' => self::CHECK_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
            'state' => $state,
            'reason' => $reason,
            'fix' => $fix,
            'checked_at' => $checkedAt,
            'next_retry_at' => $nextRetryAt,
        ];
    }

    /** A check as ConnectionChecks recorded it, with the fix its state calls for. */
    private static function stored(string $key, array $s): array
    {
        $fix = ($s['state'] ?? null) === ConnectionChecks::FAIL
            ? ($key === 'sign_in' ? self::fix('reconnect') : self::fix('retry'))
            : null;

        return self::check($key, (string) ($s['state'] ?? ConnectionChecks::UNKNOWN), $s['reason'] ?? null, $fix,
            $s['checked_at'] ?? null, $s['next_retry_at'] ?? null);
    }

    private static function fix(string $action, ?string $label = null, ?string $url = null): array
    {
        $fix = ['action' => $action, 'label' => $label ?? match ($action) {
            'reconnect' => 'Reconnect',
            'retry' => 'Try again',
            'choose_branch' => 'Choose branch',
            default => 'Open',
        }];

        return $url === null ? $fix : $fix + ['url' => $url];
    }

    /** An unsaved row so SocialConnectionAccess::inView answers for any scoped record. */
    private static function scopeOf(string $type, string $id): SocialConnection
    {
        return new SocialConnection(['scope_type' => $type, 'scope_id' => $id]);
    }

    private static function appLabel(string $provider): string
    {
        return match ($provider) {
            'phone' => 'Phone',
            'sending_server' => 'Sending server',
            'google_workspace' => 'Google Workspace',
            default => SocialProvider::tryFrom($provider)?->label() ?? $provider,
        };
    }
}
