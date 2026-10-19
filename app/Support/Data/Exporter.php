<?php

namespace App\Support\Data;

use App\Models\Customer;
use App\Models\User;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\SensitiveValues;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data out (plan §12, §9).
 *
 * Streamed rather than assembled: an organization with fifty thousand customers
 * would otherwise build the whole file in memory before sending a byte, and the
 * first client big enough to matter is the one it fails for.
 *
 * ── Reach is applied HERE, not by the model ──────────────────────
 *
 * This class shipped applying only the tenant scope, which meant any user with
 * `jobs.view` could download every franchise's jobs — the permission gate passed
 * and the reach gate was simply absent. Nothing upstream would have caught it:
 * reach in this codebase is applied per query, by hand, because it depends on
 * the caller rather than on the row.
 *
 * So every query below goes through scoped(), and which resources are
 * reach-scoped is declared in ResourceMap rather than decided here.
 *
 * ── Sensitive values do not leave through here by default ────────
 *
 * The third of the four behaviours the HR plan gives the sensitive flag:
 * "excluded from exports unless explicitly requested with permission. Otherwise
 * the first CSV puts every account number on somebody's laptop."
 *
 * Nothing reachable today can leak one — ResourceMap lists its columns by hand,
 * and neither `customers` nor `jobs` holds a tenant-defined value. That is a
 * fact about the current map rather than a property of this class, and the map
 * grows: the moment contract answers become exportable, "the columns happen not
 * to include one" stops being true and nothing would have changed here to
 * notice. So the rule is structural instead — every row is asked, and the
 * default answer is the mask.
 *
 * `$includeSensitive` is a REQUEST, not an authorisation. Even with it set, each
 * row is still asked `mayReveal()` for this caller, so a flag on a query string
 * cannot widen what somebody may see — it can only stop hiding what they were
 * already entitled to.
 *
 * ── And every value it does reveal is written down ───────────────
 *
 * This class used to read the attribute straight off the model once `mayReveal()`
 * said yes, which meant `?include_sensitive=1` was the one path in the
 * application that returned clear sensitive values with no `sensitive_value.
 * revealed` entry behind them — while SensitiveValues' docblock said "there is no
 * method that returns the clear value without auditing, which is deliberate".
 *
 * The one `data.exported` row DataController writes is not a substitute. It
 * records that a file was downloaded and that sensitive values were asked for; it
 * cannot answer "who looked at MY bank details", because it names a resource
 * rather than a record and a request rather than a field. That question is the
 * only reason the reveal log is kept.
 *
 * So the reveal goes through `SensitiveValues::read()` like every other reveal,
 * and the cost is stated rather than hidden: an export of a thousand records with
 * two sensitive columns writes two thousand audit entries. That is not a defect
 * of the logging — it is a thousand records' worth of bank details leaving the
 * building, and a log that recorded it as one line would be describing something
 * that did not happen.
 *
 * One consequence of that inside `csv()`, which streams: `read()` writes the
 * audit entry BEFORE returning the value, so a failed audit write throws
 * mid-response and the download truncates. The headers have gone by then, so
 * there is no way to turn it into a clean error. A half-written CSV is still the
 * right outcome — the alternative is the rest of the file leaving with no record
 * that it did, which is the failure this whole arrangement exists to prevent.
 */
class Exporter
{
    /** Rows per query. Big enough to be few round trips, small enough to stay flat. */
    private const CHUNK = 500;

    public function __construct(
        private TenantContext $tenant,
        private SensitiveValues $sensitive,
    ) {}

    public function csv(string $resource, User $user, bool $includeSensitive = false): StreamedResponse
    {
        $definition = ResourceMap::get($resource);
        $columns = ResourceMap::columnsFor($definition, $user);

        // Both captured HERE, not read inside the callback.
        //
        // A streamed response runs its body after the middleware stack has
        // unwound, and SetTenantContext clears the tenant in a `finally` on the
        // way out. So by the time these rows are queried there is no tenant left
        // — which is either an exception or, far worse, an unscoped query that
        // writes another organization's customers into this file. Reach is
        // resolved eagerly for the same reason.
        $organization = $this->tenant->get();
        $reach = $this->reachFor($definition, $user);

        return response()->streamDownload(function () use ($definition, $columns, $organization, $reach, $user, $includeSensitive) {
            $out = fopen('php://output', 'w');

            // Excel reads a bare UTF-8 CSV as the system codepage, so an accented
            // name comes back mangled. The BOM is what makes it open correctly,
            // and every real customer list has accented names in it.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $columns);

            $this->tenant->run($organization, function () use ($definition, $columns, $out, $reach, $user, $includeSensitive) {
                $this->scoped($definition, $reach)
                    // No orderBy here: chunkById needs to page by the column it
                    // cursors on, and a second ordering in front of it can drop
                    // rows between pages. Ids are UUIDv7, so id order IS creation
                    // order — the chronological export is preserved for free.
                    ->chunkById(self::CHUNK, function ($rows) use ($out, $columns, $user, $includeSensitive) {
                        foreach ($rows as $row) {
                            $values = $this->readable($row, $columns, $user, $includeSensitive);

                            fputcsv($out, array_map(
                                fn (string $c) => $this->cell($values[$c]),
                                $columns,
                            ));
                        }

                        flush();
                    });
            });

            fclose($out);
        }, "{$resource}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Everything, as JSON — the §9 portability answer at organization level.
     *
     * Not streamed, because the point of this one is a single coherent document
     * somebody can hand to a lawyer or load into another system, and a truncated
     * JSON file is worthless in a way a truncated CSV is not.
     */
    public function bundle(User $user, bool $includeSensitive = false): array
    {
        $out = [];

        foreach (ResourceMap::all() as $key => $definition) {
            $out[$key] = $this->scoped($definition, $this->reachFor($definition, $user))
                ->orderBy('created_at')
                ->get()
                // `readable()` rather than `only()`, so this file obeys the same
                // rule the CSV does. A portability bundle that carried values
                // the CSV masks would be the same leak through the quieter door.
                ->map(fn (Model $m) => $this->readable($m, ResourceMap::columnsFor($definition, $user), $user, $includeSensitive))
                ->all();
        }

        return $out;
    }

    /**
     * One row's columns, with anything sensitive masked unless it may be shown.
     *
     * Two conditions, and both have to hold: the caller ASKED for the values,
     * and the row itself says this caller may see them. Splitting it that way is
     * what keeps a query parameter from being an authorisation — see the note on
     * this class.
     *
     * ── Why mayReveal() is asked here and again inside read() ────
     *
     * It looks like the check is done twice, and it is, for different answers.
     * `read()` THROWS when a reveal is refused, which is right for a screen —
     * somebody pressed a button and is owed a reason. It is wrong for an export:
     * a reader who ticks "include sensitive values" should get a file with the
     * masked columns still in it, not a 403 instead of their customer list. So
     * the row is asked first to decide whether to ASK for a reveal at all, and
     * `read()` is then given an answer it will not refuse.
     *
     * What is NOT duplicated is the masking or the audit. Both are read()'s, and
     * this method no longer touches `getAttribute` on a holder — which is what
     * let a bulk reveal through here unrecorded.
     *
     * A model that does not implement HoldsSensitiveValues has no sensitive
     * attributes to ask about and is read directly, which costs one instanceof
     * per row.
     *
     * @param  string[]  $columns
     * @return array<string, mixed>
     */
    private function readable(Model $row, array $columns, User $user, bool $includeSensitive): array
    {
        if (! $row instanceof HoldsSensitiveValues) {
            return array_combine(
                $columns,
                array_map(fn (string $c) => $row->getAttribute($c), $columns),
            );
        }

        $reveal = $includeSensitive && $row->mayReveal($user);

        $out = [];

        foreach ($columns as $column) {
            // One column at a time, deliberately — there is no bulk reveal, and
            // an audit entry per field is what makes the log able to say which
            // field somebody looked at rather than only that they exported.
            $out[$column] = $this->sensitive->read($row, $column, $user, reveal: $reveal)['value'];
        }

        return $out;
    }

    /**
     * One person's data (§9, portability).
     *
     * Their record plus the work done for them. Deliberately not "every row that
     * mentions them": an audit entry naming who edited their address is somebody
     * else's activity record, and handing it over would be a disclosure rather
     * than a portability answer.
     *
     * The jobs section is gated separately, and that is the whole point of it
     * being separate. A customer is shared organization-wide, so `customers.view`
     * is rightly enough to read one — but their jobs are not shared, and this
     * endpoint was handing over every franchise's work for that customer to
     * anybody who could open their record.
     */
    public function person(Customer $customer, User $user, bool $includeSensitive = false): array
    {
        $jobs = ResourceMap::get('jobs');

        $visible = $user->hasPermission($jobs['permission'])
            ? $customer->jobs()
                ->whereIn($jobs['reach_column'], ViewScope::forUser($user)->franchiseIds())
                ->orderBy('created_at')->get()
            : collect();

        // Through `readable()` like the other two, so the one export that is
        // ABOUT a person is not the one that hands over their sensitive values
        // without being asked.
        return [
            'customer' => $this->readable(
                $customer, ResourceMap::columnsFor(ResourceMap::get('customers'), $user), $user, $includeSensitive,
            ),
            'jobs' => $visible->map(
                fn (Model $j) => $this->readable($j, ResourceMap::columnsFor($jobs, $user), $user, $includeSensitive),
            )->all(),
        ];
    }

    /**
     * The franchises this caller may see, or null when the resource is org-wide.
     *
     * null and [] mean opposite things and must not be collapsed: null is
     * "reach does not apply to this resource", [] is "this person reaches
     * nothing". Writing the filter as a conditional that skips an empty list
     * would turn the second into the first.
     */
    private function reachFor(array $definition, User $user): ?array
    {
        return isset($definition['reach_column'])
            ? ViewScope::forUser($user)->franchiseIds()
            : null;
    }

    private function scoped(array $definition, ?array $reach): Builder
    {
        $query = $definition['model']::query()->with($definition['with'] ?? []);

        return $reach === null
            ? $query
            : $query->whereIn($definition['reach_column'], $reach);
    }

    /**
     * Defuse spreadsheet formulas.
     *
     * A cell beginning = + - @ or a control character is executed when the file
     * is opened, so a customer named `=HYPERLINK("http://evil","click")` turns
     * an export into an attack on whoever opens it. Excel and Sheets both do
     * this, and the data got into the system through a legitimate form.
     *
     * Numbers are left alone — prefixing `-5` would corrupt the value, and a
     * number cannot carry a formula. A leading `+` on a phone number is still
     * escaped, because `+` genuinely starts a formula in Excel; Importer::parse
     * strips the marker back off, so a round trip is lossless.
     */
    private function cell(mixed $value): string
    {
        if ($value === null || $value === false) {
            return $value === false ? '0' : '';
        }

        if ($value === true) {
            return '1';
        }

        $string = (string) $value;

        if ($string === '' || is_numeric($string)) {
            return $string;
        }

        return str_contains("=+-@\t\r", $string[0]) ? "'".$string : $string;
    }
}
