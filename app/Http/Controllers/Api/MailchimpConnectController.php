<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Newsletter\MailchimpClient;
use App\Support\Newsletter\NewsletterSendFailed;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Connect the company's own Mailchimp, with their API key.
 *
 * The same shape Bluesky's app-password connect uses — a credential the company
 * already holds, pasted once, revocable at the provider without asking us. The
 * key is VERIFIED against Mailchimp before a row is written, so a typo is a
 * sentence on the form rather than a connection that looks fine and fails on
 * the morning somebody sends.
 */
class MailchimpConnectController extends Controller
{
    public function __construct(
        private readonly MailchimpClient $mailchimp,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission(SocialProvider::Mailchimp->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('connect a mailing account')->message(),
        );

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:120'],
        ], [
            'api_key.required' => 'Paste the API key from your Mailchimp account.',
        ]);

        $view = ViewScope::forUser($user);
        $scopeType = $view->isOrganization() ? SocialConnection::SCOPE_ORGANIZATION : $view->type;
        $scopeId = $view->isOrganization() ? (string) $user->organization_id : (string) $view->id;

        // §4's fourth gate: holding the permission is not the same as being able
        // to act HERE.
        abort_unless(
            $this->access->reachesScope($user, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('connect a mailing account')->message(),
        );

        try {
            $account = $this->mailchimp->verify($data['api_key']);
        } catch (NewsletterSendFailed $e) {
            // A bad key at connect time is a field-level refusal, not a 500 —
            // the person can fix it, and the sentence says how.
            throw ValidationException::withMessages(['api_key' => $e->getMessage()]);
        }

        $connection = SocialConnection::query()
            ->where('provider', SocialProvider::Mailchimp->value)
            ->where('remote_id', $account['dc'].':'.$account['email'])
            ->first() ?? new SocialConnection;

        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'provider' => SocialProvider::Mailchimp->value,
                'remote_id' => $account['dc'].':'.$account['email'],
            ]);
        }

        $connection->forceFill([
            'display_name' => $account['account_name'],
            'handle' => $account['email'],
        ]);

        // Stored under `credentials`, which is encrypted and masked on every
        // serialisation — the key never comes back out through any API path.
        $connection->connect([
            'refresh_token' => $data['api_key'],
            'api_key' => $data['api_key'],
            'account_email' => $account['email'],
        ]);
        $connection->save();

        $this->audit->log('social.connected', $connection, [
            'provider' => SocialProvider::Mailchimp->value,
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
        ], actor: $user);

        return response()->json(['data' => [
            'id' => $connection->getKey(),
            'provider' => SocialProvider::Mailchimp->value,
            'provider_label' => SocialProvider::Mailchimp->label(),
            'display_name' => $connection->display_name,
            'handle' => $connection->handle,
        ]], Status::HTTP_CREATED);
    }

    /**
     * The audiences that account holds, so somebody can pick one by name
     * rather than pasting a list id they would have to go and find.
     */
    public function lists(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Newsletter')->message(),
        );

        $connection = SocialConnection::query()
            ->where('provider', SocialProvider::Mailchimp->value)
            ->get()
            ->first(fn (SocialConnection $c) => $this->access->inView($user, $c));

        if ($connection === null) {
            return response()->json(['data' => [], 'message' => 'No Mailchimp account is connected here.']);
        }

        $credentials = $connection->getAttribute('credentials');
        $key = is_array($credentials) ? (string) ($credentials['api_key'] ?? '') : '';

        try {
            $lists = $this->mailchimp->lists($key);
        } catch (NewsletterSendFailed $e) {
            return response()->json(['data' => [], 'message' => $e->getMessage()]);
        }

        return response()->json(['data' => $lists]);
    }
}
