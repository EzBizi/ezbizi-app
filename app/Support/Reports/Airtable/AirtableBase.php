<?php

namespace App\Support\Reports\Airtable;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One Airtable base, reachable with one personal access token.
 *
 * ── A CONNECTION IS A BASE, NOT AN ACCOUNT ──────────────────────
 *
 * A PAT can reach several bases, and a business keeps unrelated things in
 * different ones — A-1 has the profit-and-loss in `appWhbwht0xgB5Yut` and the
 * invoices and marketing tables in `app6ZZgY9nHX8mMih`. Storing "an Airtable
 * account" would mean one row that silently answers for whichever base a
 * caller happened to name, so each connection names ONE base and a business
 * connects as many as it uses.
 *
 * And the name is Airtable's own. `GET /meta/bases` lists every base the token
 * reaches, WITH its name — "Profit And Loss", "Deposit Jobs Tracker" — so a
 * connection arrives already called something a person recognises.
 *
 * This paragraph said the opposite twice. First that Airtable would name the
 * base, from what I assumed; then, correcting that, that it never would —
 * because `/meta/bases/{id}/tables` returns table names and I generalised from
 * the one endpoint I had called to the whole API. The deploy desk said "the
 * meta API returns a base name if the connect flow can fetch it", I went to
 * disagree, and they were right. A negative about an API is a claim about
 * every endpoint in it, and I had asked one.
 *
 * ── THE TOKEN IS A HEADER, WHICH IS THE EASY CASE ───────────────
 *
 * Unlike Workiz, whose token goes in the URL path, Airtable authenticates with
 * `Authorization: Bearer`. A logger that redacts headers redacts this one. So
 * the rule this class needs is only the ordinary one: report a status, never a
 * body — an Airtable error body can quote field and table names, which is the
 * tenant's data rather than ours.
 */
final class AirtableBase
{
    private const API = 'https://api.airtable.com/v0';

    /**
     * Does this token reach this base, and what is the base called?
     *
     * Asked BEFORE a row is written, for the reason Mailchimp's connect flow
     * asks: a typo pasted into a form should be a field-level refusal somebody
     * can fix, not a connection that looks made and returns nothing on the
     * Monday a report is due.
     *
     * The meta endpoint is the cheapest question that proves both halves at
     * once: an unusable token and an unreachable base come back as different
     * statuses rather than both arriving as "no data", which is what an empty
     * read would have given.
     *
     * What it does NOT separate is a base id that is wrong from one that is
     * real and ungranted — see the 403/404 arm. An earlier version of this
     * paragraph claimed it did.
     *
     * @return array{name: string, tables: list<string>}
     *
     * @throws AirtableRefused naming the field to look at, with a status and
     *                         never a body
     */
    public function verify(string $token, string $baseId): array
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get(self::API.'/meta/bases/'.$baseId.'/tables');
        } catch (ConnectionException) {
            throw AirtableRefused::token('Airtable could not be reached.');
        }

        if ($response->status() === 401) {
            throw AirtableRefused::token('Airtable did not accept that token.');
        }

        /*
         * 403 and 404 are the SAME mistake to a person: the credential works
         * and does not reach this base. Airtable uses 404 for a base the token
         * has no grant on, because telling a stranger a base exists is itself
         * a disclosure - so an id that is wrong and an id that is real but
         * ungranted are deliberately indistinguishable, and the sentence must
         * not promise to tell them apart.
         */
        if ($response->status() === 403 || $response->status() === 404) {
            throw AirtableRefused::base(
                'That token cannot reach that base. Check the base id, and that the token was '
                .'granted access to it.'
            );
        }

        if ($response->failed()) {
            throw AirtableRefused::base('Airtable refused the request ('.$response->status().').');
        }

        $tables = array_values(array_filter(array_map(
            static fn (array $table): string => (string) ($table['name'] ?? ''),
            (array) ($response->json('tables') ?? []),
        )));

        if ($tables === []) {
            throw AirtableRefused::base('That base has no tables this token can see.');
        }

        return ['name' => $this->nameOf($token, $baseId) ?? $baseId, 'tables' => $tables];
    }

    /**
     * Every base a token reaches, as Airtable lists them (Jose, 2026-10-20:
     * "shouldn't i just need to provide the access token?"). Paged; a refused
     * token is a sentence, as in verify().
     *
     * @return list<array{id: string, name: string, permission: string}>
     */
    public function bases(string $token): array
    {
        $bases = [];
        $offset = null;

        do {
            try {
                $response = Http::withToken($token)->acceptJson()->timeout(20)
                    ->get(self::API.'/meta/bases', array_filter(['offset' => $offset]));
            } catch (ConnectionException) {
                throw AirtableRefused::token('Airtable could not be reached.');
            }
            if ($response->status() === 401) {
                throw AirtableRefused::token('Airtable did not accept that token.');
            }
            if ($response->status() === 403) {
                throw AirtableRefused::token("This token can't list your bases. Add the schema.bases:read scope to it in Airtable › Builder hub › Personal access tokens.");
            }
            if ($response->failed()) {
                throw AirtableRefused::token('Airtable refused the request ('.$response->status().').');
            }
            foreach ((array) $response->json('bases') as $base) {
                $bases[] = [
                    'id' => (string) ($base['id'] ?? ''),
                    'name' => (string) ($base['name'] ?? $base['id'] ?? ''),
                    'permission' => (string) ($base['permissionLevel'] ?? ''),
                ];
            }
            $offset = $response->json('offset');
        } while (is_string($offset) && $offset !== '');

        if ($bases === []) {
            throw AirtableRefused::token('That token reaches no bases. In Airtable, add the bases to the token\'s access list.');
        }

        return $bases;
    }

    /**
     * What Airtable calls this base, or null if it will not say.
     *
     * A SEPARATE endpoint from the tables one, which is the whole reason this
     * exists: `/meta/bases/{id}/tables` names tables, `/meta/bases` names
     * bases. Asking the first and concluding the API had no base name is the
     * mistake this method was written to undo.
     *
     * Failure is null rather than a throw. The grant has already been proved by
     * the time this runs, so a hiccup here should cost a nice label and not a
     * connection — the caller falls back to the base id, which is what the
     * whole feature did before.
     */
    private function nameOf(string $token, string $baseId): ?string
    {
        $offset = null;

        do {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->get(self::API.'/meta/bases', array_filter(['offset' => $offset]));
            } catch (ConnectionException) {
                return null;
            }

            if ($response->failed()) {
                return null;
            }

            foreach ((array) ($response->json('bases') ?? []) as $base) {
                if (($base['id'] ?? null) === $baseId) {
                    return trim((string) ($base['name'] ?? '')) ?: null;
                }
            }

            $offset = $response->json('offset');
        } while ($offset !== null);

        return null;
    }

    /**
     * Every row of one table, following Airtable's cursor.
     *
     * Plain `RuntimeException` rather than `AirtableRefused`: that carries a
     * FIELD for a connect form to point at, and there is no form here — a read
     * fails long after anybody was looking at one.
     *
     * @return list<array<string, mixed>> the `fields` object of each record
     *
     * @throws RuntimeException with a status and never a body
     */
    public function records(string $token, string $baseId, string $table, int $limit = 5000): array
    {
        $out = [];
        $offset = null;

        do {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->get(self::API.'/'.$baseId.'/'.rawurlencode($table), array_filter([
                        'pageSize' => 100,
                        'offset' => $offset,
                    ]));
            } catch (ConnectionException) {
                throw new RuntimeException('Airtable could not be reached.');
            }

            if ($response->failed()) {
                throw new RuntimeException('Airtable refused the request ('.$response->status().').');
            }

            foreach ((array) ($response->json('records') ?? []) as $record) {
                $out[] = (array) ($record['fields'] ?? []);
            }

            // Airtable pages with an opaque cursor rather than an offset, so
            // the only end condition is its absence. A count-based stop would
            // loop forever the day a page comes back short but cursored.
            $offset = $response->json('offset');
        } while ($offset !== null && count($out) < $limit);

        return $out;
    }
}
