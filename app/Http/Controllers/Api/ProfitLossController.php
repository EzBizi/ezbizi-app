<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Reports\Airtable\AirtableBase;
use App\Support\Reports\Airtable\AirtablePnl;
use App\Support\Reports\Airtable\EzbiziPnl;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Profit and loss, read live out of a business's own Airtable base.
 *
 * ── ITS OWN PERMISSION, and that is the whole reason this is a tab ──
 *
 * `profit_loss.view` rather than `reports.view`, on the same reasoning as
 * Google Ads next door: a branch manager who can measure jobs and calls should
 * not thereby see payroll, owner distributions and royalties. Those are in
 * this sheet, by name, per person. Jose's call, 2026-09-17, asked before
 * building rather than after somebody noticed.
 *
 * ── NOTHING IS STORED ───────────────────────────────────────────
 *
 * Every figure here is read from Airtable when somebody opens the screen. The
 * sheet is maintained daily by people who are not us, so a cached P&L would be
 * wrong in the one direction nobody checks — it would look fine. It also means
 * there is no row for an automation to fire on and nothing to go stale.
 * Once the base (or table) is cut over to EzBizi Financials, EzbiziPnl reads
 * EzBizi's own rows instead, through the same parser.
 *
 * ── THE READER REPORTS DISAGREEMENTS, SO SO DOES THIS ───────────
 *
 * {@see AirtablePnl} sums the detail lines and reads the sheet's own Total
 * cells only to CHECK them. Where they differ it says so and resolves nothing.
 * That list travels all the way to the screen deliberately: on A-1's own data
 * one week in thirty disagrees with ITSELF by $1,613, and a reader that
 * silently picked a side would have hidden the only part anybody can act on.
 */
class ProfitLossController extends Controller
{
    public function __construct(
        private readonly AirtableBase $airtable,
        private readonly AirtablePnl $pnl,
        private readonly SocialConnectionAccess $access,
        private readonly EzbiziPnl $ezbizi,
    ) {}

    /**
     * GET /api/v1/reports/profit-loss/sources
     *
     * The connected bases and the tables inside them, so the screen can offer
     * a picker before asking for any figures.
     *
     * A base whose token has stopped working is LISTED WITH ITS REFUSAL rather
     * than dropped. Dropping it makes a base somebody connected last week
     * vanish with no explanation, which reads as "EzBizi lost it" — the same
     * failure this module keeps meeting in other clothes.
     */
    public function sources(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->hasPermission('profit_loss.view'),
            Status::HTTP_FORBIDDEN,
            'Reading profit and loss needs the Profit & Loss permission.',
        );

        $sources = [];

        foreach ($this->reachable($user) as $connection) {
            $row = [
                'id' => (string) $connection->id,
                'name' => $connection->label ?: $connection->display_name,
                'base' => $connection->display_name,
                'tables' => [],
                'unavailable' => null,
            ];

            // A cut-over base is EzBizi's: its tables come from Financials,
            // and Airtable is not asked (its token may already be revoked).
            if (($held = $this->ezbizi->tables((string) $connection->remote_id)) !== null) {
                $row['tables'] = $held;
                $sources[] = $row;

                continue;
            }

            try {
                $row['tables'] = $this->airtable->verify(
                    $this->tokenOf($connection),
                    (string) $connection->remote_id,
                )['tables'];
            } catch (RuntimeException $e) {
                $row['unavailable'] = $e->getMessage();
            }

            $sources[] = $row;
        }

        return response()->json(['sources' => $sources]);
    }

    /**
     * GET /api/v1/reports/profit-loss
     *
     * One table's weeks, and one week's figures.
     *
     * `week` is optional and its absence is not an error: opening the tab
     * should show something. Left out, the LATEST COMPLETE week is used —
     * latest because that is what somebody is coming to look at, complete
     * because a half-entered week's numbers are not smaller, they are wrong.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->hasPermission('profit_loss.view'),
            Status::HTTP_FORBIDDEN,
            'Reading profit and loss needs the Profit & Loss permission.',
        );

        $data = $request->validate([
            'connection' => ['required', 'string'],
            'table' => ['required', 'string', 'max:200'],
            'week' => ['sometimes', 'string', 'max:200'],
        ]);

        $connection = $this->reachable($user)
            ->firstWhere('id', $data['connection']);

        abort_if(
            $connection === null,
            Status::HTTP_NOT_FOUND,
            'That Airtable connection is not one this view can read.',
        );

        // Cut over: EzBizi's Financials rows, in Airtable's column names, through the same parser.
        $held = $this->ezbizi->table((string) $connection->remote_id, $data['table']);

        try {
            $rows = $held !== null ? $this->ezbizi->rows($held) : $this->airtable->records(
                $this->tokenOf($connection),
                (string) $connection->remote_id,
                $data['table'],
            );
        } catch (RuntimeException $e) {
            /*
             * 502 rather than 500: the failure is Airtable's and the person
             * can retry. A 500 says EzBizi is broken and sends somebody to the
             * wrong place to fix it.
             */
            return response()->json(['message' => $e->getMessage()], Status::HTTP_BAD_GATEWAY);
        }

        $weeks = $this->pnl->weeks($rows);

        $week = $data['week'] ?? $this->latestComplete($rows, $weeks);

        return response()->json([
            'weeks' => $weeks,
            'week' => $week === null ? null : $this->pnl->week($rows, $week),
        ]);
    }

    /**
     * The last week in the sheet that anybody has finished filling in.
     *
     * Walked BACKWARDS from the end rather than forwards, because the sheet's
     * own row order is the only ordering these labels have — "7 Sep - 13 Sep"
     * carries no year and cannot be sorted. Null when every week is partial,
     * which is a real state on a base connected mid-week.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $weeks
     */
    private function latestComplete(array $rows, array $weeks): ?string
    {
        foreach (array_reverse($weeks) as $week) {
            if ($this->pnl->week($rows, $week)['complete']) {
                return $week;
            }
        }

        return $weeks === [] ? null : $weeks[array_key_last($weeks)];
    }

    /**
     * The Airtable connections this person can see from where they are.
     *
     * `inView` rather than the tenant scope alone, for the reason spelled out
     * in AdsPerformanceController: the tenant scope narrows to the
     * organization, and one company's profit appearing in a sibling's screen
     * is worse than most disclosures this application can make.
     *
     * @return Collection<int, SocialConnection>
     */
    private function reachable($user)
    {
        return SocialConnection::query()
            ->where('provider', SocialProvider::Airtable->value)
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();
    }

    /** The stored token, which is `encrypted:array` on the model. */
    private function tokenOf(SocialConnection $connection): string
    {
        return (string) (($connection->credentials ?? [])['api_token'] ?? '');
    }
}
