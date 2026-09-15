<?php

namespace App\Support\Reports\Experimental;

use Illuminate\Support\Facades\Http;
use RuntimeException;

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
    /** RingCentral keeps 12 months of call log; older dates return nothing. */
    public const RETENTION_MONTHS = 12;

    public function configured(): bool
    {
        $config = config('experimental_reports.ringcentral');

        return filled($config['client_id'])
            && filled($config['client_secret'])
            && filled($config['jwt']);
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
     *     queue: ?string, result: ?string, rang: list<string>, answered_by: ?string
     * }>
     */
    public function forDate(string $from, string $to): array
    {
        $token = $this->token();
        $server = rtrim((string) config('experimental_reports.ringcentral.server'), '/');

        $calls = [];
        $page = 1;

        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get($server.'/restapi/v1.0/account/~/call-log', [
                    'view' => 'Detailed',
                    'direction' => 'Inbound',
                    'dateFrom' => $from,
                    'dateTo' => $to,
                    'perPage' => 1000,
                    'page' => $page,
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'RingCentral refused the call log: '.$response->status().' '.$response->body()
                );
            }

            $body = $response->json();

            foreach ($body['records'] ?? [] as $record) {
                $calls[] = $this->call($record);
            }

            // `paging` carries the page count; stop when it says so rather than
            // when a page comes back short, which is also true of the last page
            // and would stop one page early on an exact multiple.
            $pages = (int) ($body['paging']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $pages);

        return $calls;
    }

    /**
     * The queues that say what the caller was asking for.
     *
     * Named here rather than pattern-matched, because "A1 - New Client" is a
     * label A-1 chose and can change, and a report that silently reclassified
     * every call the day somebody renamed a queue would be worse than one that
     * stopped splitting them.
     */
    public const NEW_CLIENT_QUEUE = 'A1 - New Client';

    public const EXISTING_CLIENT_QUEUE = 'A1 - Existing Client';

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
            'started_at' => $record['startTime'] ?? null,
            'from' => $record['from']['phoneNumber'] ?? null,

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
        ];
    }

    private function token(): string
    {
        $config = config('experimental_reports.ringcentral');
        $server = rtrim((string) $config['server'], '/');

        $response = Http::asForm()
            ->withBasicAuth((string) $config['client_id'], (string) $config['client_secret'])
            ->post($server.'/restapi/oauth/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => (string) $config['jwt'],
            ]);

        if ($response->failed()) {
            // Deliberately does NOT include the body: a failed token exchange
            // echoes back parts of what was sent.
            throw new RuntimeException('RingCentral refused the credentials ('.$response->status().').');
        }

        return (string) $response->json('access_token');
    }
}
