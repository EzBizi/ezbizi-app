<?php

namespace App\Support\Data;

use App\Models\Customer;
use App\Models\User;
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
 */
class Exporter
{
    /** Rows per query. Big enough to be few round trips, small enough to stay flat. */
    private const CHUNK = 500;

    public function __construct(private TenantContext $tenant) {}

    public function csv(string $resource, User $user): StreamedResponse
    {
        $definition = ResourceMap::get($resource);
        $columns    = $definition['columns'];

        // Both captured HERE, not read inside the callback.
        //
        // A streamed response runs its body after the middleware stack has
        // unwound, and SetTenantContext clears the tenant in a `finally` on the
        // way out. So by the time these rows are queried there is no tenant left
        // — which is either an exception or, far worse, an unscoped query that
        // writes another organization's customers into this file. Reach is
        // resolved eagerly for the same reason.
        $organization = $this->tenant->get();
        $reach        = $this->reachFor($definition, $user);

        return response()->streamDownload(function () use ($definition, $columns, $organization, $reach) {
            $out = fopen('php://output', 'w');

            // Excel reads a bare UTF-8 CSV as the system codepage, so an accented
            // name comes back mangled. The BOM is what makes it open correctly,
            // and every real customer list has accented names in it.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $columns);

            $this->tenant->run($organization, function () use ($definition, $columns, $out, $reach) {
                $this->scoped($definition, $reach)
                    // No orderBy here: chunkById needs to page by the column it
                    // cursors on, and a second ordering in front of it can drop
                    // rows between pages. Ids are UUIDv7, so id order IS creation
                    // order — the chronological export is preserved for free.
                    ->chunkById(self::CHUNK, function ($rows) use ($out, $columns) {
                        foreach ($rows as $row) {
                            fputcsv($out, array_map(
                                fn (string $c) => $this->cell($row->getAttribute($c)),
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
    public function bundle(User $user): array
    {
        $out = [];

        foreach (ResourceMap::all() as $key => $definition) {
            $out[$key] = $this->scoped($definition, $this->reachFor($definition, $user))
                ->orderBy('created_at')
                ->get()
                ->map(fn (Model $m) => $m->only($definition['columns']))
                ->all();
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
    public function person(Customer $customer, User $user): array
    {
        $jobs = ResourceMap::get('jobs');

        $visible = $user->hasPermission($jobs['permission'])
            ? $customer->jobs()
                ->whereIn($jobs['reach_column'], $user->reachableFranchises()->pluck('id'))
                ->orderBy('created_at')->get()
            : collect();

        return [
            'customer' => $customer->only(ResourceMap::get('customers')['columns']),
            'jobs'     => $visible->map(fn (Model $j) => $j->only($jobs['columns']))->all(),
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
            ? $user->reachableFranchises()->pluck('id')->all()
            : null;
    }

    private function scoped(array $definition, ?array $reach): Builder
    {
        $query = $definition['model']::query();

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
