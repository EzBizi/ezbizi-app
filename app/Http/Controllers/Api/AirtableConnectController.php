<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
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
            // Airtable's meta endpoint names tables but not the base itself, so
            // the base id IS the name until somebody labels it. Deliberately
            // not a guess assembled from table names: two bases with a
            // "Companies" table would then look like the same thing.
            'display_name' => $connection->display_name ?: $data['base_id'],
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
}
