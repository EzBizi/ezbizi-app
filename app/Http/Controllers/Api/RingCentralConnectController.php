<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Connect an organization's own RingCentral account (Jose, 2026-10-15).
 *
 * The organization's own RingCentral app — client id, client secret and a JWT
 * — the same three values config/experimental_reports.php reads from env.
 * Verified before a row is written: the JWT is exchanged and the account read,
 * so a typo is a sentence on the form rather than an empty call log later.
 *
 * Scoped to the current view, like Airtable. One row per RingCentral account
 * (`remote_id`), so reconnecting the same account updates it.
 */
class RingCentralConnectController extends Controller
{
    private const SERVER = 'https://platform.ringcentral.com';

    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /api/v1/social/ringcentral */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $provider = SocialProvider::RingCentral;

        abort_unless(
            $user->hasPermission($provider->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission($provider->connectAct())->message(),
        );

        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:200'],
            'client_secret' => ['required', 'string', 'max:200'],
            'jwt' => ['required', 'string', 'max:4000'],
        ], [
            'client_id.required' => 'Paste the client ID of your RingCentral app.',
            'client_secret.required' => 'Paste the client secret of your RingCentral app.',
            'jwt.required' => 'Paste a JWT credential from your RingCentral account.',
        ]);

        $view = ViewScope::forUser($user);
        $scopeType = $view->isOrganization() ? SocialConnection::SCOPE_ORGANIZATION : $view->type;
        $scopeId = $view->isOrganization() ? (string) $user->organization_id : (string) $view->id;

        abort_unless(
            $this->access->reachesScope($user, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN,
            Refusal::permission($provider->connectAct())->message(),
        );

        $account = $this->verify($data['client_id'], $data['client_secret'], $data['jwt']);

        $connection = SocialConnection::query()
            ->where('provider', $provider->value)
            ->where('remote_id', $account['id'])
            ->first() ?? new SocialConnection;

        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'provider' => $provider->value,
                'remote_id' => $account['id'],
            ]);
        }

        $connection->forceFill([
            'display_name' => $account['name'],
            'handle' => $account['id'],
        ]);

        // Encrypted and masked like every other connection's credentials.
        $connection->connect([
            'refresh_token' => $data['jwt'],
            'server' => self::SERVER,
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'],
            'jwt' => $data['jwt'],
        ]);
        $connection->save();

        $this->audit->log('social.connected', $connection, [
            'provider' => $provider->value,
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
        ], actor: $user);

        return response()->json(['data' => [
            'id' => $connection->getKey(),
            'provider' => $provider->value,
            'provider_label' => $provider->label(),
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
            'scope_id' => $connection->scope_id,
            'message' => $provider->connectedSentence(1, (string) $connection->display_name),
        ]], Status::HTTP_CREATED);
    }

    /**
     * Exchange the JWT and read the account. Refusals name the field to fix;
     * RingCentral's body is never echoed (a failed exchange repeats what was sent).
     *
     * @return array{id: string, name: string}
     */
    private function verify(string $clientId, string $secret, string $jwt): array
    {
        try {
            $token = Http::asForm()->timeout(20)
                ->withBasicAuth($clientId, $secret)
                ->post(self::SERVER.'/restapi/oauth/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['jwt' => 'RingCentral did not answer. Try again in a minute.']);
        }

        if ($token->status() === 429) {
            throw ValidationException::withMessages(['jwt' => 'RingCentral is limiting sign-ins right now. Try again in a minute.']);
        }

        if ($token->failed() || blank($token->json('access_token'))) {
            // invalid_client is the app's id/secret; anything else is the JWT.
            $field = $token->json('error') === 'invalid_client' ? 'client_secret' : 'jwt';

            throw ValidationException::withMessages([$field => $field === 'client_secret'
                ? 'RingCentral did not accept that client ID and secret. Copy both again from your app.'
                : 'RingCentral did not accept that JWT for this app. Create a new JWT credential and paste it.']);
        }

        $account = Http::withToken((string) $token->json('access_token'))->acceptJson()->timeout(20)
            ->get(self::SERVER.'/restapi/v1.0/account/~');

        if ($account->failed() || blank($account->json('id'))) {
            throw ValidationException::withMessages(['jwt' => 'RingCentral signed in but would not show the account. Give the app the Read Accounts permission.']);
        }

        $id = (string) $account->json('id');

        // The account record carries no company name; its business address
        // does. Not fatal: an app without that read still connects, named by
        // its main number.
        $company = '';
        try {
            $address = Http::withToken((string) $token->json('access_token'))->acceptJson()->timeout(20)
                ->get(self::SERVER.'/restapi/v1.0/account/~/business-address');
            $company = $address->successful() ? (string) $address->json('company') : '';
        } catch (ConnectionException) {
        }

        $name = $company !== '' ? $company : 'RingCentral '.((string) $account->json('mainNumber') ?: $id);

        return ['id' => $id, 'name' => $name];
    }
}
