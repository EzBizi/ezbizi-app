<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Airtable\AirtableBase;
use App\Support\Reports\Airtable\AirtableRefused;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Connect a business's own Airtable base.
 *
 * Generic on Jose's instruction, 2026-09-16: "make the system so future
 * businesses can integrate Airtable Themselves, so it-s not just an
 * a-1chimney thing, make the connection in Integrations". Nothing here names a
 * base id, a table, or A-1 — a tenant pastes a token and a base id, and what
 * that base contains is the reader's problem rather than the connector's.
 *
 * ── ONE ROW IS ONE BASE ─────────────────────────────────────────
 *
 * A personal access token reaches several bases. Storing "an Airtable account"
 * would be one row that answers for whichever base a caller named, which is
 * the silently-partial shape this module keeps meeting. `remote_id` is the
 * base id, so connecting the same base twice updates rather than duplicates,
 * and connecting a second base makes a second row.
 *
 * ── SCOPED TO THE CURRENT VIEW, like Mailchimp ──────────────────
 *
 * Not forced to a franchise the way Workiz is. A Workiz account IS one
 * branch's book, so anything above it has nothing to attribute to; an Airtable
 * base is whatever the business put in it, and A-1's own P&L base holds a
 * table per tenant. Forcing a franchise would make the honest case
 * unrepresentable.
 */
class AirtableConnectController extends Controller
{
    public function __construct(
        private readonly AirtableBase $airtable,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/social/airtable
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission(SocialProvider::Airtable->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::Airtable->connectAct())->message(),
        );

        $data = $request->validate([
            'api_token' => ['required', 'string', 'max:200'],

            // Airtable base ids are `app` + 14 url-safe characters. Pinned
            // rather than left free so a pasted base URL - which people do
            // paste - is refused at the field with a sentence, instead of
            // becoming a 404 from Airtable that reads like a broken token.
            'base_id' => ['required', 'string', 'regex:/^app[A-Za-z0-9]{14}$/'],
        ], [
            'api_token.required' => 'Paste a personal access token from your Airtable account.',
            'base_id.required' => 'Paste the id of the base you want EzBizi to read.',
            'base_id.regex' => 'That is not an Airtable base id. It starts with "app" and is '
                .'17 characters long — you can read it out of the base\'s URL.',
        ]);

        $view = ViewScope::forUser($user);
        $scopeType = $view->isOrganization() ? SocialConnection::SCOPE_ORGANIZATION : $view->type;
        $scopeId = $view->isOrganization() ? (string) $user->organization_id : (string) $view->id;

        // Holding the permission is not the same as being able to act HERE.
        abort_unless(
            $this->access->reachesScope($user, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::Airtable->connectAct())->message(),
        );

        try {
            $base = $this->airtable->verify($data['api_token'], $data['base_id']);
        } catch (AirtableRefused $e) {
            /*
             * A bad token or an unreachable base is a field-level refusal the
             * person can fix, not a 500 - and WHICH field is carried by the
             * throw rather than guessed here.
             *
             * It was guessed, briefly, with `str_contains($message, 'token')`,
             * and that picked wrong on its first real case: the sentence for an
             * unreachable base opens "That token cannot reach that base", so it
             * sent somebody to regenerate a credential that was fine. Matching
             * on prose makes every sentence in the client load-bearing in a way
             * nothing states.
             */
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        $connection = SocialConnection::query()
            ->where('provider', SocialProvider::Airtable->value)
            ->where('remote_id', $data['base_id'])
            ->first() ?? new SocialConnection;

        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'provider' => SocialProvider::Airtable->value,
                'remote_id' => $data['base_id'],
            ]);
        }

        $connection->forceFill([
            // Airtable's own name for the base - "Profit And Loss", "Deposit
            // Jobs Tracker" - so a connection arrives already called something
            // a person recognises rather than `appWhbwht0xgB5Yut`.
            //
            // Overwritten on every reconnect rather than kept, because this is
            // what the PROVIDER calls it and the provider is allowed to change
            // its mind. A tenant's own name lives in `label`, which is separate
            // and never touched here.
            'display_name' => $base['name'],
            'handle' => $data['base_id'],
        ]);

        // Encrypted and masked on every serialisation - the token never comes
        // back out through any API path.
        $connection->connect([
            'refresh_token' => $data['api_token'],
            'api_token' => $data['api_token'],
            'base_id' => $data['base_id'],
        ]);
        $connection->save();

        $this->audit->log('social.connected', $connection, [
            'provider' => SocialProvider::Airtable->value,
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,

            // The tables are recorded because "which base is this" is the
            // question somebody asks of the audit row months later, and a base
            // id answers it for nobody.
            'tables' => $base['tables'],
        ], actor: $user);

        return response()->json(['data' => [
            'id' => $connection->getKey(),
            'provider' => SocialProvider::Airtable->value,
            'provider_label' => SocialProvider::Airtable->label(),
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
            'scope_id' => $connection->scope_id,
            'tables' => $base['tables'],
            'message' => SocialProvider::Airtable->connectedSentence(1, $connection->display_name),
        ]], Status::HTTP_CREATED);
    }

    /**
     * Step one of connecting by token alone: every base the token reaches,
     * each marked if this view already has it. The token is not stored here.
     */
    public function bases(Request $request): JsonResponse
    {
        [$user, $scopeType, $scopeId] = $this->mayConnect($request);
        $token = $request->validate(['api_token' => ['required', 'string', 'max:200']], [
            'api_token.required' => 'Paste a personal access token from your Airtable account.',
        ])['api_token'];

        try {
            $bases = $this->airtable->bases($token);
        } catch (AirtableRefused $e) {
            throw ValidationException::withMessages(['api_token' => $e->getMessage()]);
        }
        $connected = $this->connections($scopeType, $scopeId)->pluck('remote_id')->all();

        // "N tables" beside each base; a base whose tables cannot be read shows none.
        return response()->json(['data' => array_map(function (array $b) use ($token, $connected) {
            try {
                $tables = count($this->airtable->verify($token, $b['id'])['tables']);
            } catch (AirtableRefused) {
                $tables = null;
            }

            return $b + ['tables' => $tables, 'connected' => in_array($b['id'], $connected, true)];
        }, $bases)]);
    }

    /**
     * Step two: the ticked bases are connected with the one token; bases this
     * view had that are no longer ticked are disconnected.
     */
    public function connect(Request $request): JsonResponse
    {
        [$user, $scopeType, $scopeId] = $this->mayConnect($request);
        $data = $request->validate([
            'api_token' => ['required', 'string', 'max:200'],
            'base_ids' => ['present', 'array', 'max:100'],
            'base_ids.*' => ['string', 'regex:/^app[A-Za-z0-9]{14}$/'],
        ]);

        $done = [];
        foreach (array_unique($data['base_ids']) as $baseId) {
            try {
                $base = $this->airtable->verify($data['api_token'], $baseId);
            } catch (AirtableRefused $e) {
                throw ValidationException::withMessages(['base_ids' => $baseId.': '.$e->getMessage()]);
            }
            $done[] = $this->connectBase($user, $scopeType, $scopeId, $data['api_token'], $baseId, $base);
        }

        $dropped = $this->connections($scopeType, $scopeId)->whereNotIn('remote_id', $data['base_ids']);
        foreach ($dropped as $connection) {
            $this->audit->log('social.disconnected', $connection, ['provider' => SocialProvider::Airtable->value, 'display_name' => $connection->display_name], actor: $user);
            $connection->delete();
        }

        return response()->json(['data' => [
            'connected' => array_map(fn (SocialConnection $c) => ['id' => $c->getKey(), 'base_id' => $c->remote_id, 'display_name' => $c->display_name], $done),
            'disconnected' => $dropped->count(),
            'message' => SocialProvider::Airtable->connectedSentence(count($done), $done[0]->display_name ?? ''),
        ]]);
    }

    /**
     * A new token for every base this view has connected, at once — and only
     * if it reaches all of them, so nothing is left half on the old token.
     */
    public function replaceToken(Request $request): JsonResponse
    {
        [$user, $scopeType, $scopeId] = $this->mayConnect($request);
        $token = $request->validate(['api_token' => ['required', 'string', 'max:200']])['api_token'];
        $connections = $this->connections($scopeType, $scopeId);
        if ($connections->isEmpty()) {
            throw ValidationException::withMessages(['api_token' => 'No Airtable bases are connected here yet. Connect them instead.']);
        }

        $verified = [];
        $unreachable = [];
        foreach ($connections as $connection) {
            try {
                $verified[$connection->getKey()] = $this->airtable->verify($token, (string) $connection->remote_id);
            } catch (AirtableRefused $e) {
                $unreachable[] = ($connection->display_name ?: $connection->remote_id).' ('.$e->getMessage().')';
            }
        }
        if ($unreachable !== []) {
            throw ValidationException::withMessages(['api_token' => 'The new token cannot reach: '.implode('; ', $unreachable).' Nothing was changed.']);
        }

        foreach ($connections as $connection) {
            $this->connectBase($user, $scopeType, $scopeId, $token, (string) $connection->remote_id, $verified[$connection->getKey()], replaced: true);
        }

        return response()->json(['data' => [
            'replaced' => $connections->count(),
            'message' => 'The new token now reads all '.$connections->count().' bases. The old token can be deleted in Airtable.',
        ]]);
    }

    /** @return array{0: User, 1: string, 2: string} */
    private function mayConnect(Request $request): array
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission(SocialProvider::Airtable->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::Airtable->connectAct())->message(),
        );
        $view = ViewScope::forUser($user);
        $scopeType = $view->isOrganization() ? SocialConnection::SCOPE_ORGANIZATION : $view->type;
        $scopeId = $view->isOrganization() ? (string) $user->organization_id : (string) $view->id;
        abort_unless(
            $this->access->reachesScope($user, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN,
            Refusal::permission(SocialProvider::Airtable->connectAct())->message(),
        );

        return [$user, $scopeType, $scopeId];
    }

    private function connections(string $scopeType, string $scopeId)
    {
        return SocialConnection::query()->where('provider', SocialProvider::Airtable->value)
            ->where('scope_type', $scopeType)->where('scope_id', $scopeId)->get();
    }

    private function connectBase($user, string $scopeType, string $scopeId, string $token, string $baseId, array $base, bool $replaced = false): SocialConnection
    {
        $connection = SocialConnection::query()->where('provider', SocialProvider::Airtable->value)
            ->where('remote_id', $baseId)->first() ?? new SocialConnection;
        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                'provider' => SocialProvider::Airtable->value, 'remote_id' => $baseId,
            ]);
        }
        $connection->forceFill(['display_name' => $base['name'], 'handle' => $baseId]);
        $connection->connect(['refresh_token' => $token, 'api_token' => $token, 'base_id' => $baseId]);
        $connection->save();

        $this->audit->log($replaced ? 'social.token_replaced' : 'social.connected', $connection, [
            'provider' => SocialProvider::Airtable->value,
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
            'tables' => $base['tables'],
        ], actor: $user);

        return $connection;
    }
}
