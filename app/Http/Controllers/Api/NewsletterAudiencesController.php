<?php

namespace App\Http\Controllers\Api;

use App\Models\NewsletterAudience;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Mailing lists — one built from the customer book, or one that lives at the
 * provider.
 *
 * Scoped like everything else in this module (§3): an audience files on the
 * VIEW, an organization-level one is visible down, a sibling company's is not.
 */
class NewsletterAudiencesController extends Controller
{
    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Newsletter')->message(),
        );

        $rows = NewsletterAccess::visible(NewsletterAudience::query(), $user)->orderBy('name')->get()
            ->values();

        return response()->json([
            'data' => $rows->map(fn (NewsletterAudience $a) => [
                'id' => $a->getKey(),
                'name' => $a->name,
                'kind' => $a->kind,
                'mailchimp_list_id' => $a->mailchimp_list_id,
                'tag' => $a->tag,
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('newsletter.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('change mailing lists')->message(),
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in([
                NewsletterAudience::KIND_CUSTOMERS,
                NewsletterAudience::KIND_MAILCHIMP,
            ])],
            /*
             * Required for BOTH kinds, and that is not an oversight. A
             * customers audience still needs somewhere at Mailchimp to be
             * pushed into before a campaign can go to it — see MailchimpSender.
             * An audience without one would save happily and refuse at send.
             */
            'mailchimp_list_id' => ['required', 'string', 'max:64'],
            // Optional: a Mailchimp tag, or (customers) a tag on the customer's jobs.
            'tag' => ['nullable', 'string', 'max:100'],
        ], [
            'mailchimp_list_id.required' => 'Choose which Mailchimp list this sends to. '
                .'Even a list built from your customers needs somewhere at Mailchimp to go.',
        ]);

        $view = ViewScope::forUser($user);
        abort_unless(OwnedScope::mayWrite($user, $user->organization_id, $view->type, $view->id),
            Status::HTTP_FORBIDDEN, 'Choose a view where your assignments allow creating mailing lists.');

        $scopeType = $view->isOrganization() ? SocialConnection::SCOPE_ORGANIZATION : $view->type;
        $scopeId = $view->isOrganization() ? (string) $user->organization_id : (string) $view->id;

        /*
         * Choosing a Mailchimp list that is already an audience here selects it
         * rather than making a twin: the composer's picker posts every time
         * somebody picks a list, and twins would split one list's history.
         */
        $existing = NewsletterAudience::query()
            ->where('scope_type', $scopeType)->where('scope_id', $scopeId)
            ->where('kind', $data['kind'])
            ->where('mailchimp_list_id', $data['mailchimp_list_id'])
            ->when(($data['tag'] ?? null) === null || $data['tag'] === '',
                fn ($q) => $q->where(fn ($w) => $w->whereNull('tag')->orWhere('tag', '')),
                fn ($q) => $q->where('tag', $data['tag']))
            ->first();

        if ($existing !== null) {
            return response()->json(['data' => [
                'id' => $existing->getKey(),
                'name' => $existing->name,
                'kind' => $existing->kind,
                'mailchimp_list_id' => $existing->mailchimp_list_id,
            ]], Status::HTTP_OK);
        }

        $audience = new NewsletterAudience($data);
        $audience->forceFill(['scope_type' => $scopeType, 'scope_id' => $scopeId]);
        $audience->save();

        $this->audit->log('newsletter.audience_created', $audience, [
            'name' => $audience->name,
            'kind' => $audience->kind,
        ], actor: $user);

        return response()->json(['data' => [
            'id' => $audience->getKey(),
            'name' => $audience->name,
            'kind' => $audience->kind,
            'mailchimp_list_id' => $audience->mailchimp_list_id,
        ]], Status::HTTP_CREATED);
    }

    private function inView(User $user, NewsletterAudience $a): bool
    {
        $stand = new SocialConnection;
        $stand->forceFill(['scope_type' => $a->scope_type, 'scope_id' => $a->scope_id]);

        return $this->access->inView($user, $stand);
    }
}
