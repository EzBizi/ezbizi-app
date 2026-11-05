<?php

namespace App\Support\Reports\Experimental;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Reports\ReportView;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * One day of calls from RingCentral (TEMPORARY — see config/experimental_reports.php).
 *
 * ── The account endpoint, not the extension one ──────────────────
 *
 * `/account/~/extension/~/call-log` returns only the AUTHENTICATED user's own
 * calls, which for a server app is the app's own extension and therefore
 * nothing anybody wants. `/account/~/call-log` returns the whole account, which
 * is the only way to answer "how many calls did each person take". Verified
 * against RingCentral's own guide, 2026-09-11.
 *
 * ── `view=Detailed` is what makes the question answerable ────────
 *
 * The Simple view gives one record per call. Detailed gives the LEGS, which is
 * how a queue call that rang four people and was answered by one can be
 * attributed to the one who answered rather than to all four or to none.
 *
 * ── WHERE THE PERSON ACTUALLY IS, measured 2026-09-14 ────────────
 *
 * Not where the first draft looked. The top-level record's `to` block is the
 * DIALLED NUMBER, so `to.name` reads "Main - IVR" or the caller's carrier CNAM
 * ("WIRELESS CALLER") and `to.extensionNumber` is null: on 278 of the day's 280
 * inbound calls the first draft produced a null person and would have reported
 * that nobody took any calls.
 *
 * The person is on the `PstnToSip` legs, and RingCentral puts the EXTENSION
 * OWNER in that leg's `from.name` with the extension itself in `to.
 * extensionNumber` — the opposite way round from the intuition. Measured
 * vocabulary for those legs on one day:
 *
 *     Call connected 185   IP Phone Offline 231   No Answer 31
 *     Stopped 18           Hang Up 1
 *
 * RANG and ANSWERED are therefore different questions and this class answers
 * both. One call commonly rings three or four people in turn, and every person
 * who rings also picks up a duplicate `IP Phone Offline` leg, so counting legs
 * would double every number.
 *
 * 81 of the 280 calls had NO `PstnToSip` leg at all — they ended in the IVR,
 * voicemail or the caller hanging up. Those are real received calls that
 * reached nobody, which is why `answered_by` is nullable rather than the row
 * being dropped.
 *
 * ── NEW vs EXISTING IS ON THE CALL, not inferred ─────────────────
 *
 * A-1 routes its tracking numbers through named queues, and the queue name is
 * on an `Accept` leg's `to.name`: "A1 - New Client" 135 and "A1 - Existing
 * Client" 66 on the measured day. That is the caller's own routing choice —
 * a far better answer than guessing from whether a phone number appears on an
 * older Workiz job, and it means the Workiz match becomes a CHECK of that
 * split rather than the source of it.
 *
 * ── Absent credentials are a state, not an error ─────────────────
 *
 * `configured()` is separate from `forDate()` so the screen can say "not
 * connected" rather than draw an empty report. An empty report and a missing
 * connection look identical and mean opposite things.
 */
final class RingCentralCallLog
{
    /** The one organization the env credential belongs to (Jose, 2026-10-15). Slug, not name: it was renamed. */
    public const ENV_ORGANIZATION_SLUG = 'a-1-chimney';

    public const NOT_CONNECTED = 'RingCentral is not connected. Connect it in Settings › Integrations.';

    /**
     * The company whose calls these are: a company-scoped connection's
     * company, else the env account's (REPORT_RINGCENTRAL_COMPANY). Null when
     * nobody said (Notifications' per-person company filter, 2026-10-23).
     */
    public function companyId(): ?string
    {
        $tenant = app(TenantContext::class)->get()
            ?? Organization::query()->where('slug', self::ENV_ORGANIZATION_SLUG)->first();
        $connection = $tenant === null ? null : SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $tenant->getKey())->where('provider', SocialProvider::RingCentral->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->first();

        if ($connection !== null) {
            return $connection->scope_type === SocialConnection::SCOPE_COMPANY ? (string) $connection->scope_id : null;
        }

        return config('experimental_reports.ringcentral.company_id') ?: null;
    }

    /** Set by only(): read just this connection. */
    private ?string $only = null;

    /**
     * Read one connected account. An organization can hold several (Triplex:
     * A1 Chimney's and EverDoors', 2026-10-29); without this, first() wins and
     * the others' calls are never transcribed. Null: back to the default.
     */
    public function only(?string $connectionId): void
    {
        $this->only = $connectionId;
    }

    /**
     * Every connected account of the organization (the tenant, or A1-Group for
     * a console run); [null] when there is none, the env account's case.
     *
     * @return list<string|null>
     */
    public function accounts(): array
    {
        $tenant = app(TenantContext::class)->get()
            ?? Organization::query()->where('slug', self::ENV_ORGANIZATION_SLUG)->first();
        $ids = $tenant === null ? [] : SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $tenant->getKey())->where('provider', SocialProvider::RingCentral->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->orderBy('created_at')->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $ids === [] ? [null] : $ids;
    }

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * Whose RingCentral account, and with what.
     *
     * The organization's own connection (Settings › Integrations) first; for a
     * signed-in person, one in their current view. Then the env credential, but
     * ONLY for A1-Group: it is their account, and any other organization
     * reading it would see A-1's calls. The env is retired once A1-Group's
     * connection is on the card.
     *
     * No tenant (the scheduled commands) reads as A1-Group, which is what
     * every one of them did before connections existed.
     *
     * @return array{server: string, client_id: string, client_secret: string, jwt: string, account: ?string}|null
     */
    private function credentials(): ?array
    {
        $tenant = app(TenantContext::class)->get()
            ?? Organization::query()->where('slug', self::ENV_ORGANIZATION_SLUG)->first();

        // No tenant and no A1-Group on this installation: a console run, which
        // is the env credential's own case.
        if ($tenant === null) {
            return $this->fromEnv();
        }

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $tenant->getKey())
            ->where('provider', SocialProvider::RingCentral->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->only !== null, fn ($q) => $q->whereKey($this->only))
            ->get();

        $user = auth()->user();
        if ($user !== null && $user->organization_id === $tenant->getKey()) {
            $connections = $connections->filter(fn (SocialConnection $c) => app(SocialConnectionAccess::class)->inView($user, $c));
        }

        if (($c = $connections->first()) !== null) {
            $creds = (array) ($c->credentials ?? []);

            return [
                'server' => (string) ($creds['server'] ?? 'https://platform.ringcentral.com'),
                'client_id' => (string) ($creds['client_id'] ?? ''),
                'client_secret' => (string) ($creds['client_secret'] ?? ''),
                'jwt' => (string) ($creds['jwt'] ?? ''),
                'account' => (string) $c->remote_id,
            ];
        }

        // The env account belongs to one company (REPORT_RINGCENTRAL_COMPANY,
        // 2026-10-19): a signed-in reader whose view is another company or a
        // branch outside it reads no calls, not that company's.
        if ($user !== null && ! ReportView::coversCompany(config('experimental_reports.ringcentral.company_id'))) {
            return null;
        }

        return $tenant->slug === self::ENV_ORGANIZATION_SLUG ? $this->fromEnv() : null;
    }

    /** @return array{server: string, client_id: string, client_secret: string, jwt: string, account: ?string}|null */
    private function fromEnv(): ?array
    {
        $config = config('experimental_reports.ringcentral');

        if (blank($config['client_id']) || blank($config['client_secret']) || blank($config['jwt'])) {
            return null;
        }

        return [
            'server' => (string) $config['server'],
            'client_id' => (string) $config['client_id'],
            'client_secret' => (string) $config['client_secret'],
            'jwt' => (string) $config['jwt'],
            'account' => null,
        ];
    }

    /** @return array{server: string, client_id: string, client_secret: string, jwt: string, account: ?string} */
    private function credentialsOrFail(): array
    {
        return $this->credentials() ?? throw new RuntimeException(self::NOT_CONNECTED);
    }

    private function server(): string
    {
        return rtrim($this->credentialsOrFail()['server'], '/');
    }

    /**
     * One row per INBOUND call for a day, in the account's own timezone.
     *
     * Per call and not per leg: a leg list is an implementation detail of how
     * a queue hunted for somebody, and every question asked of this report is
     * about calls.
     *
     * @return list<array{
     *     id: ?string, started_at: ?string, from: ?string, caller_label: ?string,
     *     queue: ?string, result: ?string, rang: list<string>, answered_by: ?string,
     *     talk_seconds: ?int, recording_id: ?string
     * }>
     */
    public function forDate(string $from, string $to): array
    {
        $token = $this->token();
        $server = $this->server();

        $calls = [];
        $page = 1;

        do {
            $response = $this->page($token, $server, $from, $to, $page);

            if ($response->failed()) {
                throw new RuntimeException(
                    'RingCentral refused the call log: '.$response->status().' '.$response->body()
                );
            }

            $body = $response->json();

            foreach ($body['records'] ?? [] as $record) {
                $calls[] = $this->call($record);
            }

            /*
             * ANOTHER PAGE EXISTS WHEN `navigation.nextPage` DOES.
             *
             * The call log never sends a page count. Measured on the live API
             * 2026-10-05: `paging` is {page, perPage, pageStart, pageEnd} and
             * nothing more. The loop used to read `paging.totalPages`, which
             * is absent, defaulted to 1, and so read the first 1,000 calls of
             * any range and stopped - invisible on a day (A-1 takes ~660) and
             * a third of a week. The fixture that pinned it had invented the
             * field.
             */
            $more = ! empty($body['navigation']['nextPage']['uri']);
            $page++;
        } while ($more);

        return $calls;
    }

    /**
     * Every inbound call between two instants, a DAY AT A TIME, with each
     * finished day kept for a week.
     *
     * The call log is in RingCentral's heavy rate group - ten requests a
     * minute - so a month asked for whole would wait minutes on the limit.
     * A day that has ended no longer changes, so it is fetched once and
     * reused; only today is read live. `ezbizi:warm-call-log` reads yesterday
     * each night, so a week or a month is normally all cache.
     *
     * Inbound only unless $outbound: Jose, 2026-10-12, wants missed calls told
     * apart "between inbound and outbound", and only the report shows
     * outbound ones. Transcription, labels and the missed-call alert stay on
     * customers calling in.
     *
     * @return list<array<string, mixed>>
     */
    public function forPeriod(CarbonImmutable $start, CarbonImmutable $end, bool $outbound = false): array
    {
        $calls = [];
        $today = CarbonImmutable::now($start->getTimezone())->startOfDay();

        for ($day = $start->startOfDay(); $day <= $end; $day = $day->addDay()) {
            $fetch = fn () => $this->forDate($day->toIso8601String(), $day->endOfDay()->toIso8601String());

            // Today is live, but shared for a minute: every open fetched it
            // afresh, and a burst of opens met RingCentral's 429s, whose waits
            // outran nginx's 120s (504s, 2026-11-03).
            $calls = [...$calls, ...($day < $today
                ? $this->storedDay($this->dayKeyFor($day), $fetch)
                : Cache::remember($this->dayKeyFor($day).':live', 60, $fetch))];
        }

        // Newest first across the days: each day comes newest first, but the
        // days were joined oldest first, so a week opened on its first day.
        usort($calls, fn (array $a, array $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));

        return $outbound ? $calls : array_values(array_filter($calls, fn (array $call) => $call['direction'] === 'Inbound'));
    }

    /**
     * A finished day, from EzBizi's own copy (call_log_days), fetched once.
     *
     * Jose, 2026-10-27: an integration's data stays in EzBizi. This was a
     * 7-day Cache::remember, so the reports' history went with the cache or
     * the connection. A day still in that cache is copied over rather than
     * fetched again.
     *
     * @param  callable(): list<array<string, mixed>>  $fetch
     * @return list<array<string, mixed>>
     */
    private function storedDay(string $key, callable $fetch): array
    {
        $stored = DB::table('call_log_days')->where('day_key', $key)->value('calls');
        if (is_string($stored)) {
            return json_decode($stored, true) ?? [];
        }

        $calls = Cache::get($key);
        if (! is_array($calls)) {
            $calls = $fetch();
        }

        DB::table('call_log_days')->insertOrIgnore([
            'day_key' => $key,
            'calls' => json_encode($calls),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $calls;
    }

    /** Whether a finished day is already kept, so the nightly warm skips it. */
    public function hasDay(CarbonImmutable $day): bool
    {
        return DB::table('call_log_days')->where('day_key', $this->dayKeyFor($day))->exists();
    }

    /** dayKey() for the account being read: a connected account caches apart from the env one. */
    public function dayKeyFor(CarbonImmutable $day): string
    {
        $account = $this->credentialsOrFail()['account'];

        return self::dayKey($day).($account === null ? '' : ':'.$account);
    }

    /** One finished day's cache key; the zone is in it because a day's edges are. */
    public static function dayKey(CarbonImmutable $day): string
    {
        // v2: both directions since 2026-10-12; a v1 day holds inbound only.
        return 'ringcentral-calls-v2:'.$day->toDateString().':'.$day->getTimezone()->getName();
    }

    /**
     * One page, waiting out RingCentral's rate limit rather than failing on it:
     * a 429 says how long to wait, and the report would rather be slow once
     * than refuse. Bounded, so a limit that never lifts still ends in an error.
     */
    private function page(string $token, string $server, string $from, string $to, int $page): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get($server.'/restapi/v1.0/account/~/call-log', [
                    'view' => 'Detailed',
                    'dateFrom' => $from,
                    'dateTo' => $to,
                    'perPage' => 1000,
                    'page' => $page,
                ]);

            if ($response->status() !== 429 || $attempt >= 3) {
                return $response;
            }

            Sleep::for(min(60, max(1, (int) ($response->header('Retry-After') ?: 30))))->seconds();
        }
    }

    /**
     * The queues that say what the caller was asking for: the business's own
     * names (Settings: leads.new_client_queue, leads.existing_client_queue;
     * A-1's are "A1 - New Client" and "A1 - Existing Client").
     *
     * Named rather than pattern-matched, because a queue name is a label the
     * business chose and can change, and a report that silently reclassified
     * every call the day somebody renamed a queue would be worse than one that
     * stopped splitting them. Unset, no call is either.
     */
    public static function newClientQueue(): ?string
    {
        return self::queueSetting('leads.new_client_queue');
    }

    public static function existingClientQueue(): ?string
    {
        return self::queueSetting('leads.existing_client_queue');
    }

    /** 'new', 'existing', or null: which of the two a call's queue is. */
    public static function clientOf(?string $queue): ?string
    {
        return match (true) {
            $queue === null => null,
            $queue === self::newClientQueue() => 'new',
            $queue === self::existingClientQueue() => 'existing',
            default => null,
        };
    }

    private static function queueSetting(string $key): ?string
    {
        $org = app(TenantContext::class)->get();

        return $org === null ? null : ((string) app(SettingsResolver::class)->get($key, $org) ?: null);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *     id: ?string, started_at: ?string, from: ?string, caller_label: ?string,
     *     queue: ?string, result: ?string, rang: list<string>, answered_by: ?string
     * }
     */
    private function call(array $record): array
    {
        $rang = [];
        $answeredBy = null;
        $queue = null;
        $talkSeconds = null;

        foreach ($record['legs'] ?? [] as $leg) {
            $name = $leg['to']['name'] ?? null;

            // An Accept leg's `to.name` is the queue or named line the call was
            // routed to. "Main - IVR" is the switchboard every call passes
            // through and says nothing about what the caller wanted.
            if ($name !== null && $name !== 'Main - IVR' && $queue === null) {
                $queue = (string) $name;
            }

            if (($leg['legType'] ?? null) !== 'PstnToSip') {
                continue;
            }

            // The extension OWNER is in the SIP leg's `from`, not its `to`.
            $person = $leg['from']['name'] ?? null;

            if ($person === null) {
                continue;
            }

            $person = (string) $person;

            if (! in_array($person, $rang, true)) {
                $rang[] = $person;
            }

            if (($leg['result'] ?? null) === 'Call connected' && $answeredBy === null) {
                $answeredBy = $person;

                /*
                 * THE LEG'S DURATION, NOT THE RECORD'S, and they are not close.
                 *
                 * Measured against the live API 2026-09-15 on one call: the
                 * record says 197 seconds and the connected leg says 148. The
                 * record's duration starts when the caller reaches the switch-
                 * board, so it carries the IVR and the queue wait — time
                 * nobody was on the phone. 25% out on that call.
                 *
                 * "How long was that person on the phone" is the leg.
                 */
                $talkSeconds = (int) ($leg['duration'] ?? 0);
            }
        }

        return [
            'id' => isset($record['id']) ? (string) $record['id'] : null,
            'direction' => (string) ($record['direction'] ?? 'Inbound'),
            'started_at' => $record['startTime'] ?? null,
            'from' => $record['from']['phoneNumber'] ?? null,

            // Our number they dialled. Its name is in numberNames(), not here.
            'to_number' => $record['to']['phoneNumber'] ?? null,

            // The carrier's CNAM, or - on a tracking number - a label A-1 wrote
            // that names the market and the source ("MKE - SEO - A1 - New
            // Client"). Kept verbatim; nothing here parses it.
            'caller_label' => $record['from']['name'] ?? null,

            'queue' => $queue,
            'result' => $record['result'] ?? null,

            // Everyone whose phone rang, and the one who picked up. A call that
            // reached nobody keeps an empty `rang` and a null `answered_by`
            // rather than being dropped - 81 of 280 on the measured day.
            'rang' => $rang,
            'answered_by' => $answeredBy,

            // Null when nobody picked up. Null rather than 0, because a zero
            // would average in as a real short call and pull every mean down.
            'talk_seconds' => $talkSeconds,

            // The record's own length, IVR and wait included. Leads uses it for
            // an outbound call, which has no PstnToSip leg to time.
            'duration' => isset($record['duration']) ? (int) $record['duration'] : null,

            /*
             * THE RECORDING'S ID, NOT ITS URL.
             *
             * `contentUri` points at media.ringcentral.com - a DIFFERENT host
             * from the API - and needs our bearer token to fetch, so handing
             * it to a browser gives a 401 and handing the token with it gives
             * away the account. The id is what the proxy endpoint takes.
             *
             * Null on a call with no recording, which is a real and common
             * state: measured 2026-09-17, 175 of 250 inbound calls over three
             * days carried one. A missing recording is not an error and must
             * not read as one.
             */
            'recording_id' => isset($record['recording']['id'])
                ? (string) $record['recording']['id']
                : null,
        ];
    }

    /**
     * The audio of one recording, fetched with the account's own token.
     *
     * Two hosts are involved and that is the trap: the call log lives on the
     * API host, the audio on media.ringcentral.com. The id is turned into a
     * media URL HERE rather than trusting a `contentUri` sent up from a
     * browser - a caller that can name any URL and have the server fetch it
     * with our bearer token is a request-forgery hole with a token attached.
     *
     * @return array{body: string, type: string}
     *
     * @throws RuntimeException naming what is missing, never a token
     */
    public function recording(string $id): array
    {
        $server = $this->server();

        $response = Http::withToken($this->token())
            ->get($server.'/restapi/v1.0/account/~/recording/'.rawurlencode($id).'/content');

        /*
         * 403 here is ONE thing and it is worth saying by name: the app has
         * not been granted ReadCallRecording. Measured 2026-09-17 against the
         * live account - the recordings are there and the audio refuses.
         *
         * Left as "RingCentral refused (403)" this reads like a broken build
         * and somebody spends an afternoon in the wrong codebase.
         */
        if ($response->status() === 403) {
            throw new RuntimeException(
                'The RingCentral app EzBizi uses is not allowed to read call recordings. The '
                .'recordings exist - the app needs the ReadCallRecording permission added '
                .'to it in the RingCentral developer console, which is a change there and '
                .'not in EzBizi.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException('RingCentral refused the recording ('.$response->status().').');
        }

        return [
            'body' => $response->body(),

            // Whatever RingCentral says it is. RingCentral serves wav or mp3
            // depending on how the account is configured, and guessing one
            // gives a player that silently will not play.
            'type' => $response->header('Content-Type') ?: 'audio/mpeg',
        ];
    }

    /**
     * Our numbers' names as A-1 labelled them in RingCentral ("DFW - LS Ads"),
     * by number. Needs ReadAccounts, granted 2026-10-08; 212 of 259 numbers
     * carry one. Cached a day: labels change by hand, rarely. A failure is
     * reported and reads as no names, so a missed call still gets announced.
     *
     * @return array<string, string>
     */
    /**
     * The calls going on right now, by the same id the call log uses.
     *
     * The call log never says "In Progress": a live call is listed there as
     * "Accepted" with no answered leg yet, so it read as missed (Jose,
     * 2026-10-19, +1 732-275-7027 at 1:05 PM). Only active-calls says so -
     * measured that day: its ids are the call log's.
     *
     * @return array<string, true>
     */
    public function activeCallIds(): array
    {
        $response = Http::withToken($this->token())->acceptJson()
            ->get($this->server().'/restapi/v1.0/account/~/active-calls', ['view' => 'Simple', 'perPage' => 1000]);
        if ($response->failed()) {
            throw new RuntimeException('RingCentral did not list the calls in progress ('.$response->status().').');
        }

        return collect($response->json('records') ?? [])->pluck('id')->filter()->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    public function numberNames(): array
    {
        if (is_array($cached = Cache::get($this->namesKey()))) {
            return $cached;
        }

        $server = $this->server();
        $names = [];

        try {
            for ($page = 1; $page <= 10; $page++) {
                $response = Http::withToken($this->token())->acceptJson()
                    ->get($server.'/restapi/v1.0/account/~/phone-number', ['perPage' => 1000, 'page' => $page]);

                if (! $response->successful()) {
                    throw new RuntimeException('RingCentral refused the number list ('.$response->status().').');
                }

                foreach ($response->json('records') ?? [] as $number) {
                    if (! empty($number['label']) && ! empty($number['phoneNumber'])) {
                        $names[(string) $number['phoneNumber']] = trim((string) $number['label']);
                    }
                }

                if (empty($response->json('navigation.nextPage.uri'))) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Not cached: the next run asks again.
            report($e);

            return [];
        }

        Cache::put($this->namesKey(), $names, now()->addDay());

        return $names;
    }

    /**
     * The access token, kept until a minute before it expires.
     *
     * One sign-in per request was fine for one day's report. A month is thirty
     * day-requests, and RingCentral rate-limits the sign-in itself - measured
     * 2026-10-06, `ezbizi:warm-call-log --days=31` died on a 429 from
     * /oauth/token, not from the call log. The token lasts an hour.
     */
    private function namesKey(): string
    {
        $account = $this->credentialsOrFail()['account'];

        return 'ringcentral-number-names'.($account === null ? '' : ':'.$account);
    }

    private function token(): string
    {
        $config = $this->credentialsOrFail();
        $key = 'ringcentral-token:'.sha1((string) $config['client_id']);

        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $server = rtrim((string) $config['server'], '/');

        // The sign-in is rate-limited too; a 429 is waited out like a page's.
        for ($attempt = 1; ; $attempt++) {
            $response = Http::asForm()
                ->withBasicAuth((string) $config['client_id'], (string) $config['client_secret'])
                ->post($server.'/restapi/oauth/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => (string) $config['jwt'],
                ]);

            if ($response->status() !== 429 || $attempt >= 3) {
                break;
            }

            Sleep::for(min(60, max(1, (int) ($response->header('Retry-After') ?: 30))))->seconds();
        }

        if ($response->status() === 429) {
            // Busy, not wrong: saying "refused the credentials" here sent the
            // deploy desk looking at the keys (2026-10-06).
            throw new RuntimeException('RingCentral is limiting sign-ins right now (429). Try again in a minute.');
        }

        if ($response->failed()) {
            // Deliberately does NOT include the body: a failed token exchange
            // echoes back parts of what was sent.
            throw new RuntimeException('RingCentral refused the credentials ('.$response->status().').');
        }

        $token = (string) $response->json('access_token');
        $lasts = (int) ($response->json('expires_in') ?? 0);

        if ($token !== '' && $lasts > 120) {
            Cache::put($key, $token, now()->addSeconds($lasts - 60));
        }

        return $token;
    }
}
