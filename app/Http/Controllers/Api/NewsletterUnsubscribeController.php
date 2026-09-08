<?php

namespace App\Http\Controllers\Api;

use App\Models\Newsletter;
use App\Models\NewsletterUnsubscribe;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Newsletter\NewsletterUnsubscribeLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Leaving a mailing list, from the link in the footer.
 *
 * ── Public, and unauthenticated, and it has to be ───────────────
 *
 * The person clicking is a customer of a customer. They have no EzBizi account
 * and never will, so any gate here is a gate on the only lawful way out of a
 * mailing list. It is therefore written to be safe while open rather than
 * protected by a login.
 *
 * ── Why GET does not unsubscribe anybody ────────────────────────
 *
 * Mail clients and security scanners fetch every link in a message before a
 * human sees it. A GET that changed state would unsubscribe people who never
 * clicked, and the first symptom would be a list quietly emptying. So GET
 * renders a page with a button and POST is what records it.
 *
 * This endpoint requires an authenticated recipient proof; an arbitrary email
 * address is never authority to write or emit an automation. Mailchimp campaigns
 * use its native recipient-authenticated unsubscribe link instead. A proof carries
 * the original scope so deleting a newsletter cannot invalidate a genuine opt-out.
 */
class NewsletterUnsubscribeController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    public function show(Request $request, string $newsletter): Response
    {
        $proof = (string) $request->input('proof', '');
        $payload = NewsletterUnsubscribeLink::read($proof, $newsletter);
        $email = $payload['email'];

        return response($this->page(
            'Unsubscribe',
            'Stop receiving marketing emails'.($email !== '' ? ' at '.e($email) : '').'?',
            '<form method="POST" action="'.e(url('/api/v1/newsletter/unsubscribe/'.$newsletter)).'">'
            .'<input type="hidden" name="e" value="'.e($email).'">'
            .'<input type="hidden" name="proof" value="'.e($proof).'">'
            .'<button type="submit">Yes, unsubscribe me</button></form>'
            .'<p class="q">You will still receive replies and anything about work you have booked.</p>'
        ));
    }

    public function store(Request $request, string $newsletter): Response
    {
        $payload = NewsletterUnsubscribeLink::read((string) $request->input('proof', ''), $newsletter);
        $email = $payload['email'];

        $row = Newsletter::query()->withoutGlobalScopes()->find($newsletter);

        // A link from a deleted mailing still deserves to work: the person
        // asking to leave does not care that we tidied up.
        $organization = Organization::query()->withoutGlobalScopes()->find($payload['organization']);

        if ($organization !== null) {
            $this->tenant->run($organization, function () use ($row, $email, $payload, $organization) {
                DB::transaction(function () use ($row, $email, $payload, $organization) {
                    // One lock serializes duplicate submissions, including links whose mailing was deleted.
                    Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                    $unsubscribe = NewsletterUnsubscribe::query()->firstOrCreate(
                        [
                            'organization_id' => $payload['organization'],
                            'scope_type' => $payload['scope_type'],
                            'scope_id' => $payload['scope_id'],
                            'email' => $email,
                        ],
                        ['unsubscribed_at' => now()],
                    );

                    if ($unsubscribe->wasRecentlyCreated && $row !== null) {
                        $this->events->emit('newsletter.unsubscribed', $row, [
                            'email' => $email,
                            'subject' => $row->subject,
                            'audience' => $row->audience?->name,
                        ]);
                    }
                });
            });
        }

        return response($this->page('Unsubscribe', 'You have been unsubscribed.',
            '<p class="q">You will not receive further marketing emails from this business.</p>'));
    }

    /**
     * A page, not JSON: the reader is a person in a mail client, not a client
     * application. Styled inline because it is served to somebody who has never
     * loaded this app and never will.
     */
    private function page(string $title, string $heading, string $body): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.e($title).'</title><style>'
            .'body{margin:0;background:#F5F8FA;color:#060808;font:16px/1.5 system-ui,sans-serif;'
            .'display:grid;place-items:center;min-height:100vh;padding:24px}'
            .'.c{background:#fff;border:1px solid #E9EDF1;border-radius:12px;padding:28px;max-width:460px}'
            .'h1{font-size:19px;margin:0 0 12px}.q{color:#5B6672;font-size:14px}'
            .'button{font:inherit;background:#00708F;color:#fff;border:0;border-radius:8px;'
            .'padding:11px 18px;cursor:pointer}</style></head><body><div class="c">'
            .'<h1>'.$heading.'</h1>'.$body.'</div></body></html>';
    }
}
