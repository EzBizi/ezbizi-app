<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\LeadConversation;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Tenancy\TenantContext;
use App\Models\User;
use App\Support\Web\ChatPeople;
use App\Support\Web\FormChallenge;
use App\Support\Web\WebsiteEnquiries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The chat widget and the contact form on a customer's website.
 *
 * ── Who is calling, and what that costs ──
 *
 * A visitor's browser, on a site we do not run, with nobody signed in. Every
 * endpoint here is therefore unauthenticated in the usual sense and identified
 * only by the site's PUBLIC key — the same `pk_` the values snippet uses, which
 * is safe in page source because it reads only what the site already publishes.
 *
 * That makes these the only routes in EzBizi where a stranger can create a row.
 * Three things hold the line, and none of them is optional:
 *
 *   1. The public key names the site, and the site names the company. A caller
 *      cannot choose where their enquiry lands.
 *   2. The rate limiter is per key AND per address, so one key cannot be used
 *      to fill a company's Leads list and one address cannot walk many keys.
 *   3. Reading a chat back needs the per-conversation token minted when it
 *      started. Knowing a site's public key does not let anybody read a
 *      stranger's conversation on that site.
 *
 * ponytail: no CAPTCHA. A determined bot with a real key can still post, and
 * the answer when that happens is a challenge on the widget rather than a
 * tighter limit — a limit low enough to stop a bot is low enough to lose a busy
 * afternoon's real enquiries.
 *
 * ── Why the visitor polls ──
 *
 * Replies reach the visitor because their widget asks again, not because we
 * push. Real-time would mean adding Reverb or Pusher — a daemon, a port and a
 * scaling story — to a feature with no users yet.
 *
 * ponytail: polling every few seconds; move to websockets when a customer has
 * enough concurrent chats for the poll to show up in the request log.
 */
class EnquiriesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly WebsiteEnquiries $enquiries,
    ) {}

    /** POST /api/v1/web/chat — a visitor opens a conversation. */
    public function startChat(Request $request): JsonResponse
    {
        $site = $this->siteForKey($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $input = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            // The chat's ZIP box (2026-10-01); five digits, or nothing from an older widget.
            'zip' => ['nullable', 'regex:/^[0-9]{5}(?:-[0-9]{4})?$/'],
            'page_url' => ['nullable', 'string', 'max:500'],
            // Same pair as the form. See submitForm.
            'marketing_consent' => ['nullable', 'boolean'],
            'marketing_consent_text' => ['nullable', 'string', 'max:500'],
            // The robot check every website form carries (2026-10-06).
            'captcha' => ['nullable', 'string', 'max:2000'],
        ]);

        if (config('ezbizi.web_form_captcha') && ! FormChallenge::passes($input['captcha'] ?? null)) {
            return response()->json(['message' => 'The chat could not confirm you are a person. Please try again.'],
                Response::HTTP_UNPROCESSABLE_ENTITY)->withHeaders($this->browserHeaders());
        }

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        /*
         * A chat the customer has switched off does not open, even though the
         * widget asking is on their own page.
         *
         * The bubble goes away by not being sent (`WidgetDefaults::published`),
         * and that is enough for a browser loading the page now. It is not
         * enough on its own: page caches, a tab left open since yesterday and
         * anybody who kept the public key can all still reach this endpoint,
         * and a switch that leaves those able to file enquiries into a business
         * that stopped answering them has not disabled anything.
         *
         * 409 rather than 403, following Clock In: the request conflicts with
         * the current state of the site, and an administrator flipping one
         * switch makes it work. 403 is the code a permission failure returns
         * and this is not one — nothing here is signed in.
         *
         * `say()` and `messages()` are deliberately NOT gated. A visitor
         * halfway through a conversation when somebody flips the switch is a
         * person mid-sentence, and the business can still see the thread in
         * Leads; cutting them off would lose an enquiry that was already made.
         */
        if (! $this->tenant->run($organization, fn () => WebWidget::chatIsOn($site))) {
            return response()->json(
                ['message' => 'This website is not taking chat messages right now.'],
                Response::HTTP_CONFLICT,
            )->withHeaders($this->browserHeaders());
        }

        return $this->tenant->run($organization, function () use ($site, $input): JsonResponse {
            [$chat, $token] = $this->enquiries->startChat($site, $input['message'], [
                'name' => $input['name'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'zip' => $input['zip'] ?? null,
                'page_url' => $input['page_url'] ?? null,
                'marketing_consent' => (bool) ($input['marketing_consent'] ?? false),
                'marketing_consent_text' => $input['marketing_consent_text'] ?? null,
            ]);

            return response()->json([
                'chat' => $chat->getKey(),
                // Said once. It is hashed on our side and cannot be re-read.
                'token' => $token,
                ...$this->threadFor($chat),
            ], Response::HTTP_CREATED)->withHeaders($this->browserHeaders());
        });
    }

    /** POST /api/v1/web/chat/{chat}/messages — the visitor says something more. */
    public function say(Request $request, string $chat): JsonResponse
    {
        $found = $this->chatForToken($request, $chat);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        $input = $request->validate([
            'message' => ['required_without:photo', 'nullable', 'string', 'max:4000'],
            // A photo, as A1's chat took: the chimney, the crack, the door.
            'photo' => ['sometimes', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,heic,heif'],
        ]);

        $organization = $this->tenant->runWithoutTenant(fn () => $found->organization);

        return $this->tenant->run($organization, function () use ($found, $input, $request): JsonResponse {
            $attachments = [];

            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');
                $file->storeAs('web-form', $name, 'local');
                $attachments[] = URL::signedRoute('web.form.file', ['name' => $name]);
            }

            $this->enquiries->say($found, (string) ($input['message'] ?? ''), LeadConversation::FROM_CONSUMER, attachments: $attachments);

            return response()->json([
                ...$this->threadFor($found),
            ])->withHeaders($this->browserHeaders());
        });
    }

    /** GET /api/v1/web/chat/{chat}/messages — the widget asking whether anybody answered. */
    public function messages(Request $request, string $chat): JsonResponse
    {
        $found = $this->chatForToken($request, $chat);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        $organization = $this->tenant->runWithoutTenant(fn () => $found->organization);

        return $this->tenant->run($organization, function () use ($found): JsonResponse {
            $found->forceFill(['last_seen_at' => now()])->save();

            return response()->json([
                ...$this->threadFor($found),
            ])->withHeaders($this->browserHeaders());
        });
    }

    /** POST /api/v1/web/form — one submitted contact form. */
    public function submitForm(Request $request): JsonResponse
    {
        $site = $this->siteForKey($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $input = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:40'],
            'fields.*' => ['nullable', 'string', 'max:4000'],
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'page_url' => ['nullable', 'string', 'max:500'],
            // Whether they ticked "email me news", and the words beside the box.
            // Optional: an older widget sends neither, which records no consent.
            'marketing_consent' => ['nullable', 'boolean'],
            'marketing_consent_text' => ['nullable', 'string', 'max:500'],
            // A file field's uploads, by the field's label (Toula). Photos of
            // a chimney, mostly, and the odd PDF quote.
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,heic,heif,pdf,doc,docx,txt'],
            'captcha' => ['nullable', 'string', 'max:2000'],
        ]);

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $input, $request): JsonResponse {
            $form = WebWidget::resolvedFor($site)['form'] ?? [];

            if ((config('ezbizi.web_form_captcha') || ! empty($form['captcha'])) && ! FormChallenge::passes($input['captcha'] ?? null)) {
                return response()->json(['message' => 'The form could not confirm you are a person. Please try again.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY)->withHeaders($this->browserHeaders());
            }

            /*
             * Kept by EzBizi, linked from the enquiry with a signed address:
             * the Leads thread shows attachments as links, as it does Google's.
             * ponytail: an erased enquiry drops the link, not the file; sweep
             * web-form/ for unreferenced files if that ever matters.
             */
            $attachments = [];

            foreach ($request->file('files', []) as $label => $file) {
                $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');
                $file->storeAs('web-form', $name, 'local');
                $attachments[] = URL::signedRoute('web.form.file', ['name' => $name]);
                $input['fields'][(string) $label] = $file->getClientOriginalName();
            }

            $this->enquiries->submitForm($site, $input['fields'], [
                'name' => $input['name'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'page_url' => $input['page_url'] ?? null,
                'marketing_consent' => (bool) ($input['marketing_consent'] ?? false),
                'marketing_consent_text' => $input['marketing_consent_text'] ?? null,
            ], $attachments);

            $this->notify($site, (string) ($form['notify'] ?? ''), $input['fields'], $attachments);

            /*
             * No lead id in the body. The visitor has no use for it, and handing
             * a stranger the primary key of a row in somebody's CRM is a habit
             * worth not starting.
             */
            return response()->json(['received' => true], Response::HTTP_CREATED)
                ->withHeaders($this->browserHeaders());
        });
    }

    /** POST /api/v1/web/chat/{chat}/typing — the visitor is typing; Leads shows it for a few seconds. */
    public function typing(Request $request, string $chat): JsonResponse
    {
        $found = $this->chatForToken($request, $chat);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        ChatPeople::visitorTyping((string) $found->lead_id);

        return response()->json(['ok' => true])->withHeaders($this->browserHeaders());
    }

    /** GET /api/v1/web/chat/avatar/{user} — a dispatcher's photo, by the signed link a chat was given. */
    public function avatar(string $user): StreamedResponse
    {
        $subject = $this->tenant->runWithoutTenant(fn () => User::query()->find($user));
        abort_if($subject === null || $subject->avatar_path === null, Response::HTTP_NOT_FOUND);

        return app(\App\Support\Files\FileStore::class)->serve($subject->avatar_path, disposition: 'inline');
    }

    /** GET /api/v1/web/form/challenge — the puzzle a CAPTCHA'd form solves first. */
    public function challenge(): JsonResponse
    {
        return response()->json(FormChallenge::issue())->withHeaders($this->browserHeaders());
    }

    /** GET /api/v1/web/form/files/{name} — an uploaded file, behind its signed link. */
    public function file(string $name): StreamedResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}\.[a-z0-9]{1,5}$/', $name) === 1
            && Storage::disk('local')->exists('web-form/'.$name), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->response('web-form/'.$name);
    }

    /**
     * The form's own recipients, emailed the enquiry — after the response, and
     * never the visitor's problem when mail is down: the lead is already saved.
     *
     * @param  array<string, ?string>  $fields
     * @param  list<string>  $attachments
     */
    private function notify(WebSite $site, string $to, array $fields, array $attachments): void
    {
        $to = array_values(array_filter(array_map('trim', explode(',', $to))));

        if ($to === []) {
            return;
        }

        $body = "A new enquiry from {$site->host}:\n\n";

        foreach ($fields as $label => $value) {
            if (trim((string) $value) !== '') {
                $body .= $label.': '.trim((string) $value)."\n";
            }
        }

        $body .= ($attachments === [] ? '' : "\nFiles:\n".implode("\n", $attachments)."\n")
            ."\nIt is also in EzBizi, under Leads.";
        $subject = 'New enquiry from '.$site->host;

        dispatch(function () use ($to, $body, $subject) {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        })->afterResponse();
    }

    /** The site a public key names, or the refusal to send instead. */
    private function siteForKey(Request $request): WebSite|JsonResponse
    {
        $key = $request->input('key');

        if (! is_string($key) || $key === '') {
            return response()->json(
                ['message' => 'No key was given.'],
                Response::HTTP_BAD_REQUEST,
            )->withHeaders($this->browserHeaders());
        }

        $site = $this->tenant->runWithoutTenant(
            fn () => WebSite::query()->where('public_key', $key)->first(),
        );

        if ($site === null) {
            // The same answer a rotated key gets, for the reason the values
            // endpoint gives: two answers is an oracle for which keys are real.
            return response()->json(
                ['message' => 'That key is not valid.'],
                Response::HTTP_NOT_FOUND,
            )->withHeaders($this->browserHeaders());
        }

        return $site;
    }

    /**
     * The chat a visitor's token names, checked against the id in the path.
     *
     * Both must agree. Checking the token alone would let a leaked one read any
     * chat; checking the id alone would let anybody read every chat.
     */
    private function chatForToken(Request $request, string $chat): WebChat|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return response()->json(
                ['message' => 'This conversation needs its token.'],
                Response::HTTP_UNAUTHORIZED,
            )->withHeaders($this->browserHeaders());
        }

        $found = $this->tenant->runWithoutTenant(
            fn () => WebChat::query()
                ->where('id', $chat)
                ->where('token_hash', hash('sha256', $token))
                ->first(),
        );

        if ($found === null) {
            return response()->json(
                ['message' => 'That conversation is not yours.'],
                Response::HTTP_UNAUTHORIZED,
            )->withHeaders($this->browserHeaders());
        }

        return $found;
    }

    /**
     * The thread as the visitor may see it.
     *
     * Deliberately not the LeadConversation rows themselves: those carry an id,
     * a remote id and an organization id, none of which is the visitor's
     * business, and a widget that received them would put them in page source.
     *
     * @return array<int, array{from: string, text: ?string, at: ?string}>
     */
    private function messagesFor(WebChat $chat): array
    {
        /*
         * occurred_at is a second-precision timestamp, so two messages sent
         * inside the same second tie on it — and a tie's order is whatever the
         * engine feels like. The visitor's question came back AFTER the
         * business's answer once the row count around it changed, which is how
         * the tie showed itself rather than staying theoretical.
         *
         * The id breaks it: LeadConversation ids are UUID v7, time-ordered to
         * the millisecond, so the tiebreak is the real arrival order rather
         * than an arbitrary-but-stable one. Found and fixed by the Leads module
         * at 0ca6739b; my own attempt used created_at, which carries the same
         * second precision and therefore tied in exactly the same place.
         */
        $senders = ChatPeople::senders($chat->lead);

        return LeadConversation::query()
            ->where('lead_id', $chat->lead_id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'participant', 'text', 'occurred_at', 'attachments', 'is_automatic'])
            ->map(fn (LeadConversation $row) => [
                'from' => $row->participant === LeadConversation::FROM_CONSUMER ? 'you' : 'them',
                'text' => $row->text,
                'at' => $row->occurred_at?->toIso8601String(),
                // Who answered: a first name and a face, or null for the company itself (a greeting).
                'by' => isset($senders[$row->id]) ? ChatPeople::person($senders[$row->id]) : null,
                'attachments' => $row->attachments ?? [],
            ])
            ->all();
    }

    /**
     * What the widget needs beside the messages: the person answering (the
     * latest who replied), and whoever is typing right now.
     *
     * @return array<string, mixed>
     */
    private function threadFor(WebChat $chat): array
    {
        $messages = $this->messagesFor($chat);
        $agent = null;

        foreach (array_reverse($messages) as $m) {
            if ($m['by'] !== null) {
                $agent = $m['by'];
                break;
            }
        }

        $typing = ChatPeople::typingAgent((string) $chat->lead_id);

        return ['messages' => $messages, 'agent' => $agent, 'typing' => $typing ? ChatPeople::person($typing) : null];
    }

    /**
     * Callable from any origin, because it is called from the customer's site.
     *
     * The same argument the values endpoint makes: this is public data going
     * out and a public key coming in, on a host that is not ours by design.
     */
    private function browserHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ];
    }
}
