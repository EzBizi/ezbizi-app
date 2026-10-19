<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\BackfillWorkizJobs;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Attach one Workiz account to one branch (TEMPORARY).
 *
 * Goes with the Experimental report. Modelled on `MailchimpConnectController`,
 * which is the house shape for a provider that connects with a pasted
 * credential rather than an OAuth window.
 *
 * ── A BRANCH VIEW IS REQUIRED, and that is the ruling made enforceable ──
 *
 * Jose, 2026-09-14: "connect them to each of their designated franchises."
 * Mailchimp takes whatever the current view is, and for a mailing account that
 * is right. For Workiz it is not: A-1 runs ten accounts, each holding ONE
 * branch's book, and the accounts are disjoint — measured, each token answers
 * 204 for another account's job. An account connected at organization level
 * would be a book with no branch to attribute it to, and the report would go
 * back to being organization-wide, which is the thing the ruling changes.
 *
 * So this refuses outside a branch view rather than quietly scoping wider. It
 * is one condition to relax if a Workiz account ever spans branches.
 *
 * ── The token is verified before a row exists ───────────────────
 *
 * A typo makes a connection that looks made and returns nothing days later.
 * Verified, it is a sentence in the field somebody can act on.
 *
 * ── Workiz cannot tell us its own account's name ────────────────
 *
 * Nothing in the v1 payload identifies the account: `LocationId` and
 * `LocationKey` are the CUSTOMER's service address, one per job. So the
 * display name is the person's word, defaulted to the branch's own name, and
 * `remote_id` is the branch — because one branch has one Workiz account, and
 * that is the identity this report actually needs.
 */
class WorkizConnectController extends Controller
{
    public function __construct(
        private readonly WorkizJobs $workiz,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission(SocialProvider::Workiz->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::Workiz->connectAct())->message(),
        );

        $data = $request->validate([
            'api_token' => ['required', 'string', 'max:120'],
            // The account's second key, beside the token on Workiz's Developer
            // page. Without it EzBizi reads the jobs but cannot change them.
            'auth_secret' => ['nullable', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:120'],
        ], [
            'api_token.required' => 'Paste the API key from the v2 tab of this account’s Workiz Developer page (or the v1 tab’s API Token).',
        ]);

        $view = ViewScope::forUser($user);

        if ($view->type !== ViewScope::FRANCHISE) {
            throw ValidationException::withMessages([
                'api_token' => 'Switch to the branch this Workiz account belongs to, then connect it there. '
                    .'Each Workiz account holds one branch’s jobs, and a report cannot say whose they are '
                    .'unless the account is attached to that branch.',
            ]);
        }

        $scopeId = (string) $view->id;

        // Holding the permission is not the same as being able to act HERE.
        abort_unless(
            $this->access->reachesScope($user, SocialConnection::SCOPE_FRANCHISE, $scopeId),
            Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::Workiz->connectAct())->message(),
        );

        try {
            $version = $this->workiz->verify($data['api_token']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['api_token' => $e->getMessage()]);
        }

        $connection = SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)
            ->where('remote_id', $scopeId)
            ->first() ?? new SocialConnection;

        $isNew = ! $connection->exists;

        if ($isNew) {
            $connection->forceFill([
                'organization_id' => $user->organization_id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $scopeId,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $scopeId,
            ]);
        }

        $connection->forceFill([
            'display_name' => $data['label'] ?? $this->branchName($view),
        ]);

        // `credentials` is encrypted and masked on every serialisation, so the
        // token never comes back out through any API path. It matters more than
        // usual here: these are Workiz v1 ACCOUNT tokens, which cannot be
        // scoped to read-only — measured, the per-resource permissions wizard
        // belongs to Workiz's v2 system and its key is refused by the v1
        // endpoint this reads. A leaked one can delete A-1's jobs.
        // connect() replaces the credentials whole, so a reconnect that left
        // the secret blank would switch this branch's writes off unsaid. The
        // same token keeps the secret it had; a new token is a new account.
        $previous = (array) ($connection->credentials ?? []);
        $secret = $version === 'v2' ? null : ($data['auth_secret']
            ?? (($previous['api_token'] ?? null) === $data['api_token'] ? ($previous['auth_secret'] ?? null) : null));

        // A v2 key (Jose, 2026-10-05: every branch moves to one) writes as far
        // as its own permissions go and needs no secret. A branch moving to it
        // keeps its v1 pair, encrypted beside it, so it can move back without
        // asking Workiz for one again.
        $v1 = match (true) {
            $version === 'v1' => null,
            ($previous['api_version'] ?? 'v1') === 'v1' && ($previous['api_token'] ?? '') !== '' => array_filter([
                'api_token' => $previous['api_token'], 'auth_secret' => $previous['auth_secret'] ?? null,
            ]),
            default => $previous['v1'] ?? null,
        };

        $connection->connect(array_filter([
            'refresh_token' => $data['api_token'],
            'api_token' => $data['api_token'],
            'auth_secret' => $secret,
            'api_version' => $version === 'v2' ? 'v2' : null,
            'v1' => $v1,
        ]));
        $connection->save();

        // A new account brings its whole history, paced on the schedule. No
        // automation fires for it: the projection raises none for an old job.
        if ($isNew) {
            BackfillWorkizJobs::queue($connection);
        }

        $this->audit->log('social.connected', $connection, [
            'provider' => SocialProvider::Workiz->value,
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
            'api_version' => $version,
            'write_key' => $version === 'v2' || $secret !== null,
        ], actor: $user);

        return response()->json(['data' => [
            'id' => $connection->getKey(),
            'provider' => SocialProvider::Workiz->value,
            'provider_label' => SocialProvider::Workiz->label(),
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
        ]], Status::HTTP_CREATED);
    }

    private function branchName(ViewScope $view): string
    {
        return Franchise::find($view->id)?->name ?? 'Workiz';
    }
}
