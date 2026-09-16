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
 * NOT because Airtable will tell us what the base is called. It will not: the
 * meta endpoint names the base's TABLES and never the base, so a connection is
 * displayed by its id until somebody labels it. I wrote the opposite here
 * first, from what I expected the endpoint to return rather than from what it
 * returned.
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
     * @return array{tables: list<string>}
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

        return ['tables' => $tables];
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
