<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Data\Exporter;
use App\Support\Data\Importer;
use App\Support\Data\ResourceMap;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Import and export (plan §12, §9).
 *
 * Export sits on GET and under /exports, which is what keeps it working after a
 * subscription lapses — §13 lists "exporting their data" among the things that
 * must remain permitted, on the grounds that refusing looks like hostage-taking.
 * Import is a POST and is not exempt, because §13 is equally explicit that an
 * import which still runs means the product works fine without paying.
 */
class DataController extends Controller
{
    public function __construct(
        private Exporter $exporter,
        private Importer $importer,
        private AuditLogger $audit,
    ) {}

    /**
     * What this organization can export and import.
     *
     * The client builds its screen from this rather than from a list of its own,
     * so a new resource appears in the UI without a client release.
     */
    public function catalogue(Request $request): array
    {
        $user = $request->user();
        $out  = [];

        foreach (ResourceMap::all() as $key => $definition) {
            if (! $user->hasPermission($definition['permission'])) {
                continue;
            }

            $importable = isset($definition['import'])
                && $user->hasPermission($definition['import']['permission']);

            $out[] = [
                'key'        => $key,
                'label'      => $definition['label'],
                'columns'    => $definition['columns'],
                'importable' => $importable,
                'import_columns' => $importable
                    ? array_keys($definition['import']['columns'])
                    : [],
            ];
        }

        return ['resources' => $out];
    }

    public function export(Request $request, string $resource)
    {
        $definition = $this->definitionOr404($resource);

        $this->denyUnless($request, $definition['permission']);

        // A bulk export of every customer is the single most useful thing to
        // steal from this system, so it is logged as its own act rather than
        // disappearing among ordinary reads (§10).
        $this->audit->log('data.exported', metadata: ['resource' => $resource, 'format' => 'csv']);

        return $this->exporter->csv($resource, $request->user());
    }

    /** Everything, as one JSON document — the §9 portability answer. */
    public function bundle(Request $request): array
    {
        foreach (ResourceMap::all() as $definition) {
            $this->denyUnless($request, $definition['permission']);
        }

        $this->audit->log('data.exported', metadata: ['resource' => 'all', 'format' => 'json']);

        return $this->exporter->bundle($request->user());
    }

    /** One person's record and work history (§9). */
    public function person(Request $request, Customer $customer): array
    {
        $this->authorize('view', $customer);

        $this->audit->log('data.exported', $customer, metadata: ['resource' => 'person']);

        return $this->exporter->person($customer, $request->user());
    }

    public function import(Request $request, string $resource)
    {
        $definition = $this->definitionOr404($resource);

        if (! isset($definition['import'])) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "{$definition['label']} cannot be imported.");
        }

        $this->denyUnless($request, $definition['import']['permission']);

        $input = $request->validate([
            // The file, or the same content pasted in — the second is what makes
            // this testable and scriptable, and costs one line.
            'file'         => ['required_without:csv', 'file', 'mimes:csv,txt', 'max:10240'],
            'csv'          => ['required_without:file', 'string'],
            'franchise_id' => ['required', 'uuid'],
            'dry_run'      => ['boolean'],
        ]);

        $franchise = Franchise::findOrFail($input['franchise_id']);

        // The franchise that will own every row. Checked with the same rule that
        // guards creating one by hand — an import is not a way around a policy.
        $this->authorize('create', [$definition['model'], $franchise]);

        $csv = $request->hasFile('file')
            ? file_get_contents($request->file('file')->getRealPath())
            : $input['csv'];

        try {
            return $this->importer->run(
                $resource, $csv, $franchise,
                dryRun: (bool) ($input['dry_run'] ?? false),
            );
        } catch (RuntimeException $e) {
            // Whole-file problems — wrong columns, empty, too big. A 422 with the
            // reason, not a 500: the file is the user's to fix.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
    }

    private function definitionOr404(string $resource): array
    {
        return ResourceMap::get($resource) ?? abort(Response::HTTP_NOT_FOUND);
    }

    private function denyUnless(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), Response::HTTP_FORBIDDEN);
    }
}
