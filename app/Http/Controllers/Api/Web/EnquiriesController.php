<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\User;
use App\Models\WebBooking;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Files\FileStore;
use App\Support\Memberships\CustomerLinks;
use App\Support\Scheduling\NearbySuggestion;
use App\Support\Scheduling\Openings;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\BookingLink;
use App\Support\Web\ChatPeople;
use App\Support\Web\FormChallenge;
use App\Support\Web\Turnstile;
use App\Support\Web\WebsiteEnquiries;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            // And the box the visitor ticks (2026-10-08).
            'turnstile' => ['nullable', 'string', 'max:4000'],
        ]);

        if (config('ezbizi.web_form_captcha') && (! FormChallenge::passes($input['captcha'] ?? null)
            || (Turnstile::required($site) && ! Turnstile::passes($site, $input['turnstile'] ?? null)))) {
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
            'turnstile' => ['nullable', 'string', 'max:4000'],
            // The form's ZIP (2026-10-08); the lead keeps it only when it is one.
            'zip' => ['nullable', 'string', 'max:20'],
            // A review link's customer token: the faces' sad form sends it
            // instead of asking for a name, phone and email (Jose, 2026-10-08).
            'c' => ['nullable', 'string', 'max:200'],
        ]);

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $input, $request): JsonResponse {
            $form = WebWidget::resolvedFor($site)['form'] ?? [];

            if ((config('ezbizi.web_form_captcha') || ! empty($form['captcha'])) && (! FormChallenge::passes($input['captcha'] ?? null)
                || (Turnstile::required($site) && ! Turnstile::passes($site, $input['turnstile'] ?? null)))) {
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

            // Who the review link names, filled in here so it is never on the page.
            $customerId = CustomerLinks::customerId($input['c'] ?? null);
            $customer = $customerId === null ? null : Customer::query()->find($customerId);

            if ($customer !== null) {
                $input['name'] = $input['name'] ?? $customer->name;
                $input['phone'] = $input['phone'] ?? $customer->phone;
                $input['email'] = $input['email'] ?? (filter_var($customer->email, FILTER_VALIDATE_EMAIL) ? $customer->email : null);
                $job = ($jobId = CustomerLinks::jobId($input['c'])) === null ? null : Job::query()->find($jobId);
                $input['fields'] = ['Customer' => trim($customer->name.' '.($customer->number ?? ''))]
                    + ($job === null ? [] : ['Job' => trim(($job->number ?? '').' '.($job->title ?? ''))])
                    + $input['fields'];
            }

            $this->enquiries->submitForm($site, $input['fields'], [
                'name' => $input['name'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'zip' => $input['zip'] ?? null,
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

        return app(FileStore::class)->serve($subject->avatar_path, disposition: 'inline');
    }

    /**
     * GET /api/v1/web/booking/days?key=&zip= — the booking widget's calendar
     * (Jose, 2026-10-08): the next open days and arrival windows of the
     * service area covering the visitor's ZIP, from the same Openings the
     * dispatchers' Availability tab reads. Only windows with room are offered.
     */
    public function bookingDays(Request $request): JsonResponse
    {
        $site = $this->siteForKey($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $zip = $request->validate(['zip' => ['required', 'regex:/^[0-9]{5}$/']])['zip'];
        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $zip, $organization): JsonResponse {
            $area = $this->bookingArea($site, $zip);

            if ($area === null) {
                return $this->noBooking();
            }

            $days = $this->bookingOpen($area, $organization);

            return response()->json(['days' => $days, 'preferred' => $this->bookingPreferred($area, $zip, $organization, $days)])
                ->withHeaders($this->browserHeaders());
        });
    }

    /**
     * GET /api/v1/web/booking/lead?key&l — what a lead's booking link already
     * knows (Jose, 2026-10-13), so the widget skips asking for it. A first name
     * and a ZIP, never the phone itself: the link travels in a text message.
     */
    public function bookingLead(Request $request): JsonResponse
    {
        $site = $this->siteForKey($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $request): JsonResponse {
            $lead = $this->bookingLeadFor($site, $request->query('l'));

            if ($lead instanceof JsonResponse) {
                return $lead;
            }

            return response()->json([
                'first_name' => Str::of((string) $lead->contact_name)->trim()->before(' ')->value() ?: null,
                'zip' => preg_match('/^[0-9]{5}/', (string) $lead->postal_code, $m) === 1 ? $m[0] : null,
                'has_name' => trim((string) $lead->contact_name) !== '',
                'has_phone' => trim((string) $lead->phone) !== '',
            ])->withHeaders($this->browserHeaders());
        });
    }

    /**
     * The lead a booking link names, if it is this site's company's and can still
     * be booked; otherwise the answer the widget shows. A lead that already has a
     * job is a customer to talk to, not a slot to take.
     */
    private function bookingLeadFor(WebSite $site, mixed $token): Lead|JsonResponse
    {
        $id = BookingLink::leadId(is_string($token) ? $token : null);
        $lead = $id === null ? null : Lead::query()->whereKey($id)->where('company_id', $site->company_id)->first();

        if ($lead === null) {
            return response()->json(['message' => 'This booking link has expired. Please book below or call us.'],
                Response::HTTP_NOT_FOUND)->withHeaders($this->browserHeaders());
        }

        if ($lead->job_id !== null || in_array($lead->status, [Lead::STATUS_BOOKED, Lead::STATUS_COMPLETED], true)) {
            return response()->json(['message' => 'You already have a visit with us. Please call us to change it.'],
                Response::HTTP_CONFLICT)->withHeaders($this->browserHeaders());
        }

        // One request per lead: Leads reads a lead's booking as one row (Leads, 2026-10-13).
        $asked = WebBooking::query()->where('lead_id', $lead->getKey())
            ->whereIn('status', [WebBooking::PENDING, WebBooking::APPROVED])->first();

        if ($asked !== null) {
            return response()->json(['message' => 'You already asked for '.CarbonImmutable::parse($asked->date)->format('l, F j').". We'll confirm shortly."],
                Response::HTTP_CONFLICT)->withHeaders($this->browserHeaders());
        }

        return $lead;
    }

    /** POST /api/v1/web/booking — a visitor asks for a day and window; a dispatcher approves it in Leads. */
    public function submitBooking(Request $request): JsonResponse
    {
        $site = $this->siteForKey($request);

        if ($site instanceof JsonResponse) {
            return $site;
        }

        // A lead's own link: what the lead already holds is not asked again.
        $linked = $request->filled('l');

        $input = $request->validate([
            'zip' => ['required', 'regex:/^[0-9]{5}$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'l' => ['nullable', 'string', 'max:200'],
            'name' => [$linked ? 'nullable' : 'required', 'string', 'max:191'],
            'phone' => [$linked ? 'nullable' : 'required', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:300'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'captcha' => ['nullable', 'string', 'max:2000'],
            'turnstile' => ['nullable', 'string', 'max:4000'],
        ]);

        // Spam bookings would hold real seats, so the robot check is never off here.
        if (! FormChallenge::passes($input['captcha'] ?? null)
            || (Turnstile::required($site) && ! Turnstile::passes($site, $input['turnstile'] ?? null))) {
            return response()->json(['message' => 'The booking could not confirm you are a person. Please try again.'],
                Response::HTTP_UNPROCESSABLE_ENTITY)->withHeaders($this->browserHeaders());
        }

        $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

        return $this->tenant->run($organization, function () use ($site, $input, $organization, $linked): JsonResponse {
            $lead = null;

            if ($linked) {
                $lead = $this->bookingLeadFor($site, $input['l']);

                if ($lead instanceof JsonResponse) {
                    return $lead;
                }

                // Whatever the lead lacks is still asked for.
                foreach (['name' => 'contact_name', 'phone' => 'phone'] as $field => $column) {
                    if (trim((string) $lead->{$column}) === '' && trim((string) ($input[$field] ?? '')) === '') {
                        return response()->json(['message' => 'Please fill in your '.$field.'.', 'errors' => [$field => ['Required.']]],
                            Response::HTTP_UNPROCESSABLE_ENTITY)->withHeaders($this->browserHeaders());
                    }
                }
            }

            $area = $this->bookingArea($site, $input['zip']);

            if ($area === null) {
                return $this->noBooking();
            }

            // One booking at a time per area, so two visitors cannot take the last seat together.
            return Cache::lock('web-booking:'.($area->shares_with_id ?? $area->id), 10)->block(5, function () use ($site, $area, $input, $organization, $lead): JsonResponse {
                $day = collect($this->bookingOpen($area, $organization))->firstWhere('date', $input['date']);
                $window = collect($day['windows'] ?? [])->firstWhere('start_time', $input['start_time']);

                if ($window === null) {
                    return response()->json(['message' => 'That time was just taken. Please pick another.'],
                        Response::HTTP_CONFLICT)->withHeaders($this->browserHeaders());
                }

                DB::transaction(fn () => $this->enquiries->submitBooking($site, $area, $input['date'], $window, $input['address'], [
                    'name' => $input['name'] ?? null,
                    'phone' => $input['phone'] ?? null,
                    'zip' => $input['zip'],
                    'page_url' => $input['page_url'] ?? null,
                ], $lead));

                return response()->json(['received' => true, 'date' => $input['date']] + $window, Response::HTTP_CREATED)
                    ->withHeaders($this->browserHeaders());
            });
        });
    }

    /**
     * The area covering a ZIP among the site's company's branches, if it takes
     * bookings. A sub-company books against its parent's availability (Jose,
     * 2026-10-20: "the sub companies use the same availability as the parent
     * company"), while its lead stays its own.
     */
    private function bookingArea(WebSite $site, string $zip): ?ServiceArea
    {
        $company = Company::query()->find($site->company_id);
        $owner = $company?->parent_company_id ?? $site->company_id;
        $franchises = Franchise::query()->whereIn('company_id', array_unique([$site->company_id, $owner]))->pluck('id');
        $area = ServiceAreaZip::query()->whereIn('franchise_id', $franchises)->where('zip', $zip)->first()?->serviceArea;

        return $area !== null && Openings::hasCapacity($area) ? $area : null;
    }

    /**
     * The next 12 days with a window that has room, as the widget draws them.
     * ponytail: looks 60 days ahead; an area booked solid past that offers nothing.
     *
     * @return list<array{date: string, windows: list<array{start_time: string, end_time: string}>}>
     */
    private function bookingOpen(ServiceArea $area, mixed $organization): array
    {
        $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $organization));
        $open = [];

        foreach (app(Openings::class)->days($area, $from, 60) as $day) {
            $windows = array_values(array_map(
                fn (array $w) => ['start_time' => $w['start_time'], 'end_time' => $w['end_time']],
                array_filter($day['windows'], fn (array $w) => $w['remaining'] > 0),
            ));

            if ($windows !== []) {
                $open[] = ['date' => $day['date'], 'windows' => $windows];
            }

            if (count($open) === 12) {
                break;
            }
        }

        return $open;
    }

    /**
     * The window Scheduling would route best (Jose, 2026-10-13: bookings "inline
     * with the new scheduling", and "the client does not need to see the
     * recommendation"). The widget lists it first in its day, unlabelled.
     *
     * Only the date and start time leave here: the rest of the suggestion names
     * booked customers and their addresses, which never reach a public endpoint.
     * Null when nothing nearby is booked, or when the pick is not an open window.
     *
     * @param  list<array{date: string, windows: list<array{start_time: string, end_time: string}>}>  $days
     * @return array{date: string, start_time: string}|null
     */
    private function bookingPreferred(ServiceArea $area, string $zip, mixed $organization, array $days): ?array
    {
        try {
            $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $organization));
            $pick = app(NearbySuggestion::class)->for($area, $zip, $from)['suggested'] ?? null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $date = (string) ($pick['date'] ?? '');
        $start = (string) ($pick['window']['start_time'] ?? '');
        $open = collect($days)->firstWhere('date', $date);

        return $open !== null && collect($open['windows'])->contains('start_time', $start) ? ['date' => $date, 'start_time' => $start] : null;
    }

    /** An honest unknown: not "we don't serve you", just not bookable online. */
    private function noBooking(): JsonResponse
    {
        return response()->json(['message' => 'Online booking is not available for that ZIP code yet. Please call us.'],
            Response::HTTP_NOT_FOUND)->withHeaders($this->browserHeaders());
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
