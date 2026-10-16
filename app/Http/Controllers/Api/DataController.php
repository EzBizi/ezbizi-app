<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Data\Exporter;
use App\Support\Data\Importer;
use App\Support\Data\ResourceMap;
use App\Support\Privacy\DataInventory;
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
    /**
     * The two whole-organization acts, worded once.
     *
     * Each is used twice — by the endpoint that refuses it and by the catalogue
     * that tells the Settings screen in advance whether it will. They were two
     * string literals in two methods, kept identical by hand, and the parity
     * between the disabled control and the 403 behind it (see
     * `wholeOrganizationAnswers`) rests entirely on their being the same words.
     * A constant is what makes that structural instead of remembered.
     */
    private const EXPORT_EVERYTHING = 'export everything this organization holds';

    private const READ_INVENTORY = 'read the record of what this organization holds about people';

    public function __construct(
        private Exporter $exporter,
        private Importer $importer,
        private AuditLogger $audit,
        private DataInventory $inventory,
    ) {}

    /**
     * What this organization can export and import.
     *
     * The client builds its controls from this rather than from a list of its
     * own, so a new resource becomes exportable without a client release.
     *
     * ── Read by several screens now, not one ─────────────────────
     *
     * There used to be an "Import & export" tab that listed every resource in
     * one place. The owner's call was that per-resource transfer belongs IN the
     * tab that owns the records — export customers from Customers, jobs from
     * Jobs — so this answer is now consumed by each of those screens, which
     * picks out its own `key` and ignores the rest.
     *
     * The two ORGANIZATION-WIDE §9 answers, `bundle` and `inventory`, had no
     * home in a per-tab model: "export everything this business holds" is not a
     * Customers feature. They live in Settings, and `organization` below is what
     * lets that screen offer them — or say why it cannot — without asking a
     * second endpoint.
     */
    public function catalogue(Request $request): array
    {
        $user = $request->user();
        $out = [];

        foreach (ResourceMap::all() as $key => $definition) {
            if (! $user->hasPermission($definition['permission'])) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $definition['label'],
                'columns' => ResourceMap::columnsFor($definition, $user),

                /*
                 * Whether this person may take the whole resource, as one
                 * nullable SENTENCE — null means the Export control works, a
                 * string is what to show instead.
                 *
                 * Being listed here means "you may read this resource"; it used
                 * to mean "you may export it" as well, because those were one
                 * permission. They are two now (ResourceMap), and the tab still
                 * needs the entry either way: somebody who may import customers
                 * and not export them must still get the Import control and the
                 * import columns, so filtering the resource out over export
                 * would take away the wrong thing.
                 *
                 * A sentence rather than the key, unlike `import_permission`
                 * below. The two shapes are both live and the difference is
                 * deliberate: the organization answers already publish their
                 * refusal as a sentence, and that is the shape that cannot
                 * drift — the words are Refusal's, so the greyed control and the
                 * 403 behind it cannot describe one refusal two ways (R8).
                 * `import_permission` predates that and stays as it is, because
                 * the client composes an identical sentence from it and changing
                 * the wire shape of a working control is not part of this.
                 */
                'export_refusal' => $this->mayExport($user, $definition)
                    ? null
                    : Refusal::permission($this->exportAct($definition))->message(),

                /*
                 * A property of the RESOURCE, not of the caller.
                 *
                 * This used to mean "this person may import", which folded two
                 * different answers into one false-y value: jobs cannot be
                 * imported by anybody (see ResourceMap), and somebody without
                 * `customers.create` may not import customers. The screen could
                 * not tell them apart, so it drew nothing in both cases — and
                 * an absent control explains nothing (R8).
                 *
                 * Split in two: this says whether the resource supports import
                 * at all, and `import_permission` says which grant it takes, so
                 * the tab can render the control with the sentence rather than
                 * silently omit it.
                 */
                'importable' => isset($definition['import']),
                'import_permission' => $definition['import']['permission'] ?? null,
                'import_columns' => isset($definition['import'])
                    ? array_keys($definition['import']['columns'])
                    : [],
            ];
        }

        return [
            'resources' => $out,
            'organization' => $this->wholeOrganizationAnswers($user),
        ];
    }

    /**
     * Whether this caller may take the two whole-organization §9 answers.
     *
     * One nullable SENTENCE each rather than a flag beside a reason, which is
     * the shape a setting's `not_yet_in_effect` already uses: null means the
     * control works, a string is what to show instead, and the two halves cannot
     * drift apart. The wording is `Refusal`'s, verbatim, so a disabled control
     * and the 403 behind it cannot describe one refusal two ways (R8).
     *
     * Two sentences and not one, because they refuse two different acts and the
     * person reading has to know which one they were denied.
     *
     * ── They used to be the same question, and are not any more ──
     *
     * Both were decided by "does this person hold the view permission of every
     * resource", so the two sentences always appeared together. Since export
     * became a permission of its own they are two questions, and a role can
     * genuinely be refused one and allowed the other — see the loops in `bundle`
     * and `inventory` for why that is the right answer rather than an
     * inconsistency.
     */
    private function wholeOrganizationAnswers(User $user): array
    {
        $resources = collect(ResourceMap::all());

        $mayExportEverything = $resources->every(
            fn (array $definition) => $this->mayExport($user, $definition)
        );

        $mayReadEverything = $resources->every(
            fn (array $definition) => $user->hasPermission($definition['permission'])
        );

        return [
            'export_refusal' => $mayExportEverything
                ? null
                : Refusal::permission(self::EXPORT_EVERYTHING)->message(),
            'inventory_refusal' => $mayReadEverything
                ? null
                : Refusal::permission(self::READ_INVENTORY)->message(),
        ];
    }

    /**
     * May this person take every row of this resource, in one file?
     *
     * Both halves, because they answer different questions and neither implies
     * the other on its own: `permission` is may you read this resource at all —
     * the tab (§4) — and `export.permission` is may you take all of it at once.
     * Stored roles cannot hold the second without the first (implied view, see
     * PermissionCatalogue::withImpliedView), which makes the conjunction
     * belt-and-braces rather than load-bearing today; it is written out anyway
     * so that this method means what its name says regardless of how the row
     * got there.
     */
    private function mayExport(User $user, array $definition): bool
    {
        return $user->hasPermission($definition['permission'])
            && $user->hasPermission($definition['export']['permission']);
    }

    /** "export customers" — the act, worded once for the 403 and the catalogue. */
    private function exportAct(array $definition): string
    {
        return 'export '.strtolower($definition['label']);
    }

    public function export(Request $request, string $resource)
    {
        $definition = $this->definitionOr404($resource);

        $this->denyUnlessMayExport($request, $definition, $this->exportAct($definition));

        $includeSensitive = $request->boolean('include_sensitive');

        // A bulk export of every customer is the single most useful thing to
        // steal from this system, so it is logged as its own act rather than
        // disappearing among ordinary reads (§10). Asking for sensitive values
        // is recorded on the same entry rather than as a second one: it is a
        // property of this export, and two entries would have to be joined up by
        // whoever read them.
        $this->audit->log('data.exported', metadata: [
            'resource' => $resource,
            'format' => 'csv',
            'sensitive_values_requested' => $includeSensitive,
        ]);

        return $this->exporter->csv($resource, $request->user(), $includeSensitive);
    }

    /**
     * Everything, as one JSON document — the §9 portability answer.
     *
     * ── Why this takes the EXPORT permission of every resource ───
     *
     * It used to take the view permission of every resource, which was the same
     * gate the per-resource export used. Now that they differ, leaving this one
     * on view would be a hole rather than a leniency: this endpoint returns
     * every row of every resource in the map — see Exporter::bundle, which walks
     * ResourceMap and reads them all — so a role refused `customers.export`
     * could fetch the identical customer list from here, in JSON, one URL over.
     * A permission with a second door beside it is not a permission.
     *
     * Every resource and not just one, which is what it already required and
     * what makes it the strictest gate in this file: "export everything" is a
     * bigger act than any single export, so it cannot be reachable by somebody
     * who has been refused a part of it.
     */
    public function bundle(Request $request): array
    {
        foreach (ResourceMap::all() as $definition) {
            $this->denyUnlessMayExport($request, $definition, self::EXPORT_EVERYTHING);
        }

        $includeSensitive = $request->boolean('include_sensitive');

        $this->audit->log('data.exported', metadata: [
            'resource' => 'all',
            'format' => 'json',
            'sensitive_values_requested' => $includeSensitive,
        ]);

        return $this->exporter->bundle($request->user(), $includeSensitive);
    }

    /**
     * GET /api/v1/exports/inventory — where this organization's personal data is (§9).
     *
     * ── Why it lives here rather than in a module of its own ─────
     *
     * §9's three "build now" mechanisms are export, purge and inventory, and
     * they are three answers to one question somebody asks about their own data.
     * Two of them are already on this controller. A `privacy` module would mean a
     * new tab permission, a new sidebar entry and a screen nobody has designed,
     * to separate the third from the two it belongs beside.
     *
     * ── What it does and does not expose ─────────────────────────
     *
     * Table names, field names, purposes, retention and row counts. No personal
     * data at all — this is the MAP, not the territory. DataInventory builds it
     * from each model's `personalDataHeld()` declarations, and a
     * PersonalDataEntry carries a column name, a category and a sentence about
     * why the column exists; there is no path in it that reads a row.
     *
     * ── Why it did NOT move to the export permissions ────────────
     *
     * It was gated on "the view permission of every resource", the same set
     * `bundle` required, and when `bundle` moved to the EXPORT permission of
     * every resource this was the obvious next line to change. It is deliberately
     * left alone, and this paragraph is here because the symmetry will look like
     * an oversight to whoever reads the two loops next.
     *
     * `customers.export` exists to govern one thing: personal data leaving in
     * bulk, in a file. That is exactly what `bundle` does and exactly what this
     * does not — refusing it would withhold no name, no address and no phone
     * number from anybody, because it holds none. What it would withhold is the
     * §9 answer to "what do you hold about people" from somebody who may still
     * read every one of those people individually, which protects nothing and
     * costs a compliance obligation.
     *
     * So the gate stays what it always was on its own terms: it describes every
     * resource, so reading it means being allowed to READ all of them. An
     * organization that wants to withhold the map can still do it — by
     * withholding a resource's view permission, which is the permission this
     * asks for.
     */
    public function inventory(Request $request): array
    {
        foreach (ResourceMap::all() as $definition) {
            $this->denyUnless($request, $definition['permission'], self::READ_INVENTORY);
        }

        // Recorded, because "who asked what we hold about people" is exactly the
        // kind of question a compliance answer should itself be able to answer.
        $this->audit->log('data.inventory_read');

        return $this->inventory->forOrganization($request->user()->organization);
    }

    /** One person's record and work history (§9). */
    public function person(Request $request, Customer $customer): array
    {
        $this->authorize('view', $customer);

        $includeSensitive = $request->boolean('include_sensitive');

        $this->audit->log('data.exported', $customer, metadata: [
            'resource' => 'person',
            'sensitive_values_requested' => $includeSensitive,
        ]);

        return $this->exporter->person($customer, $request->user(), $includeSensitive);
    }

    public function import(Request $request, string $resource)
    {
        $definition = $this->definitionOr404($resource);

        if (! isset($definition['import'])) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "{$definition['label']} cannot be imported.");
        }

        $this->denyUnless(
            $request,
            $definition['import']['permission'],
            'import '.strtolower($definition['label']),
        );

        $input = $request->validate([
            // The file, or the same content pasted in — the second is what makes
            // this testable and scriptable, and costs one line.
            'file' => ['required_without:csv', 'file', 'mimes:csv,txt', 'max:10240'],
            'csv' => ['required_without:file', 'string'],
            'franchise_id' => ['required', 'uuid'],
            'dry_run' => ['boolean'],
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

    /**
     * Both halves of "may you take all of this", refused as ONE sentence.
     *
     * The conjunction is `mayExport`'s; this is the enforcing half, and the two
     * are kept in one file on purpose — the catalogue promises in advance what
     * this will decide, and a screen that greys a control the endpoint would
     * have allowed (or the reverse) is worse than either behaviour on its own.
     *
     * One [$act] for two checks, deliberately. They are two permissions and one
     * request: somebody who is missing either of them tried to do exactly one
     * thing, and telling them which internal check failed would be naming a
     * permission key at them, which is the thing R8 forbids. The remedy is the
     * same sentence in both cases — ask whoever edits roles.
     */
    private function denyUnlessMayExport(Request $request, array $definition, string $act): void
    {
        $this->denyUnless($request, $definition['permission'], $act);
        $this->denyUnless($request, $definition['export']['permission'], $act);
    }

    /**
     * Refuse, in a sentence, rather than with a bare 403.
     *
     * `abort_unless(..., 403)` with no message renders "This action is
     * unauthorized." — the framework's words, which name no permission and no
     * remedy (R8). Every call site in this file sits outside the policies, so
     * they were missed when the policies were given sentences; the wording still
     * comes from the same place, so there is one home for it.
     *
     * [$act] is what they were trying to do, as an infinitive without "to" —
     * the same contract Refusal::permission states.
     */
    private function denyUnless(Request $request, string $permission, string $act): void
    {
        abort_unless(
            $request->user()->hasPermission($permission),
            Response::HTTP_FORBIDDEN,
            Refusal::permission($act)->message(),
        );
    }
}
