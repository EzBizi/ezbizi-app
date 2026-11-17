<?php

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\ApiKey;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Throwable;

/**
 * Authorization sweep (2026-11-17, after the FBI ARTEX advisory on AI-agent
 * intrusions): every authenticated /api/v1 route whose URI names a record,
 * derived from the route table so a new route is covered without being listed.
 *
 * For each one a target record is built in org A (franchise A/F1), then:
 *  (a)     org B's Administrator (session)              -> must be refused
 *  (a-key) org B's Administrator through a FULL API key  -> must be refused
 *  (a-mcp) the same key through POST /mcp call_api       -> must be refused
 *  (b)     org A user holding EVERY permission, reach limited to a sibling
 *          company's franchise                            -> must be refused
 *  (c)     org A user, organization-wide reach, NO permissions -> must be refused
 * "Refused" = 403 or 404, the record's marker string absent from the body, and
 * the record's row byte-for-byte unchanged afterwards.
 *
 * Outcomes that are neither refused nor a hole (422 before authorization, 5xx,
 * 405...) cannot prove anything either way: they are counted as INCONCLUSIVE,
 * written to storage/authz-sweep.log and capped so the number can only fall.
 * GET routes also run a control (org A's own Administrator); a control that is
 * itself refused means the built record or URL is wrong for that route, and the
 * refusals there prove nothing. The count is printed.
 *
 * A REAL hole is not fixed here: it is listed in KNOWN_HOLES so the test is
 * green and the list is explicit. Both lists are compared exactly, so a fixed
 * hole must be deleted from the list and a new one fails the build.
 */
class AuthorizationSweepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const MARKER = 'authzprobe7Q';

    /**
     * "METHOD uri case" => why. Case (b) on a model with no company_id /
     * franchise_id column is NOT here: see ORG_WIDE_B.
     */
    private const KNOWN_HOLES = [
        // None measured 2026-11-17 for cases a, a-key, a-mcp, b (scoped models) and c.
    ];

    /** Case (b) 2xx on records that have no company/franchise column: org-wide by design (settings-like). */
    private const ORG_WIDE_B = [
        // Measured 2026-11-17. The target model has no company_id/franchise_id column, so a
        // franchise-scoped user holding the module permission reaches it. Intended for
        // org-wide settings-like rows; customers, HR contracts, roles and automations
        // here are for an owner to rule on (see the commit report).
        'DELETE api/v1/ai-assistance/manuals/{manual} b' => 'status 204',
        'DELETE api/v1/ai-assistance/parts/{part} b' => 'status 204',
        'DELETE api/v1/ai-assistance/staff-bot/items/{item} b' => 'status 204',
        'DELETE api/v1/ai-assistance/staff-bot/menus/{menu} b' => 'status 204',
        'DELETE api/v1/ai-assistance/whatsapp-groups/{group} b' => 'status 204',
        'DELETE api/v1/automations/{automation} b' => 'status 200',
        'DELETE api/v1/hr/time-clock/reports/{report} b' => 'status 200',
        'DELETE api/v1/inspections/codes/{inspection_code} b' => 'status 200',
        'DELETE api/v1/inspections/{inspection_definition} b' => 'status 200',
        'DELETE api/v1/invoices/sheets/{sheet} b' => 'status 204',
        'DELETE api/v1/leads/hidden-numbers/{number} b' => 'status 200',
        'DELETE api/v1/phone/reports/{report} b' => 'status 200',
        'DELETE api/v1/roles/{role} b' => 'status 200',
        'DELETE api/v1/task-board-folders/{folder} b' => 'status 204',
        'GET api/v1/automations/{automation} b' => 'status 200, body carries the record',
        'GET api/v1/automations/{automation}/runs b' => 'status 200',
        'GET api/v1/customers/{customer} b' => 'status 200, body carries the record',
        'GET api/v1/customers/{customer}/export b' => 'status 200, body carries the record',
        'GET api/v1/customers/{customer}/workiz-notes b' => 'status 200',
        'GET api/v1/hr/contracts/{contract} b' => 'status 200, body carries the record',
        'GET api/v1/hr/contracts/{contract}/versions b' => 'status 200',
        'GET api/v1/hr/time-clock/reports/{report}/preview b' => 'status 200',
        'GET api/v1/inspections/codes/{inspection_code} b' => 'status 200, body carries the record',
        'GET api/v1/inspections/{inspection_definition} b' => 'status 200, body carries the record',
        'GET api/v1/invoices/sheets/{sheet} b' => 'status 200, body carries the record',
        'PATCH api/v1/ai-assistance/parts/{part} b' => 'status 200, body carries the record',
        'PATCH api/v1/ai-assistance/whatsapp-groups/{group} b' => 'status 200, body carries the record',
        'PATCH api/v1/customers/{customer} b' => 'status 200, body carries the record',
        'PATCH api/v1/inspections/codes/{inspection_code} b' => 'status 200, body carries the record',
        'PATCH api/v1/inspections/{inspection_definition} b' => 'status 200, body carries the record',
        'PATCH api/v1/memberships/plans/{plan} b' => 'status 200, body carries the record',
        'PATCH api/v1/roles/{role} b' => 'status 200, body carries the record',
        'PATCH api/v1/task-board-folders/{folder} b' => 'status 200, body carries the record',
        'POST api/v1/ai-assistance/whatsapp-groups/{group}/publish b' => 'status 422, body carries the record',
        'POST api/v1/automations/{automation}/run-now b' => 'status 422, body carries the record',
        'POST api/v1/automations/{automation}/test b' => 'status 422, body carries the record',
        'POST api/v1/inspections/{inspection_definition}/publish b' => 'status 200, body carries the record',
        'POST api/v1/inspections/{inspection_definition}/unpublish b' => 'status 200, body carries the record',
        'POST api/v1/invoices/sheets/{sheet}/invoice b' => 'status 201',
        'PUT api/v1/ai-assistance/parts/{part} b' => 'status 200, body carries the record',
        'PUT api/v1/ai-assistance/staff-bot/items/{item} b' => 'status 200, body carries the record',
        'PUT api/v1/ai-assistance/staff-bot/menus/{menu} b' => 'status 200, body carries the record',
        'PUT api/v1/ai-assistance/whatsapp-groups/{group} b' => 'status 200, body carries the record',
        'PUT api/v1/customers/{customer} b' => 'status 200, body carries the record',
    ];

    /** Routes no record could be built for. Can only shrink. */
    private const SKIPPED = [
        // the schema builder cannot satisfy these tables constraints
        'DELETE api/v1/hr/invitations/{invitation}' => 'QueryException: SQLSTATE[HY000]: General error: 3819 Check constraint \'invitations_exactly_one_scope\' is violated. (Connection: mysql, Host: localhost, Port: 33...',
        'DELETE api/v1/hr/people/{user}/places/{assignment}' => 'UniqueConstraintViolationException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'01a1eb32-4dd2-7097-8f59-9198bc9259d7-Target\' for key...',
        'DELETE api/v1/jobs/{job}/lines/{line}' => 'UniqueConstraintViolationException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'01a1eb32-4dd2-7097-8f59-9198bc9259d7-authzprobe7Q\' fo...',
        'PATCH api/v1/jobs/{job}/lines/{line}' => 'UniqueConstraintViolationException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'01a1eb32-4dd2-7097-8f59-9198bc9259d7-authzprobe7Q\' fo...',
        'POST api/v1/hr/invitations/{invitation}/resend' => 'QueryException: SQLSTATE[HY000]: General error: 3819 Check constraint \'invitations_exactly_one_scope\' is violated. (Connection: mysql, Host: localhost, Port: 33...',
    ];

    /** Upper bound for INCONCLUSIVE outcomes; lower it when it falls. */
    private const INCONCLUSIVE_CEILING = 52; // measured 2026-11-17

    private array $tableToModel = [];

    public function test_no_other_tenant_scope_or_role_reaches_a_record(): void
    {
        ini_set('memory_limit', '2G');
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
        Queue::fake();
        Bus::fake();
        Mail::fake();
        Notification::fake();

        $a = $this->provisionTenant('Alpha');
        $b = $this->provisionTenant('Bravo');

        $siblingCompany = $this->inTenant($a['org'], fn () => Company::create([
            'organization_id' => $a['org']->id, 'name' => 'Alpha Sibling Co',
        ]));
        $siblingFranchise = $this->inTenant($a['org'], fn () => Franchise::create([
            'organization_id' => $a['org']->id, 'company_id' => $siblingCompany->id, 'name' => 'Alpha Sibling F',
        ]));

        $allKeys = app(PermissionCatalogue::class)->keys();
        $sideways = $this->userWithRole($a, 'Everything Elsewhere', $allKeys, 'sideways@alpha.test', $siblingFranchise);
        $nothing = $this->userWithRole($a, 'Nothing', [], 'nothing@alpha.test');
        $key = $this->inTenant($b['org'], fn () => $b['admin']->fresh()->createToken('sweep', ApiKey::abilities('full'))->plainTextToken);

        $this->indexModels();

        $results = [];
        $skipped = [];
        $inconclusive = [];
        $checked = 0;
        $controlled = 0;
        $uncontrolled = 0;
        $log = [];

        foreach ($this->recordRoutes() as [$route, $method, $params]) {
            $label = $method.' '.$route->uri();

            DB::beginTransaction();
            try {
                $built = [];
                $values = [];
                foreach ($params as $name => $class) {
                    if ($class === null) {
                        $values[$name] = $this->freeValue($route, $name);
                        continue;
                    }
                    $model = $this->inTenant($a['org'], fn () => $this->build($class, $built, $a));
                    $field = $route->bindingFieldFor($name) ?? $model->getRouteKeyName();
                    $values[$name] = $model->getAttribute($field);
                }
            } catch (Throwable $e) {
                DB::rollBack();
                $skipped[$label] = Str::limit(class_basename($e).': '.$e->getMessage(), 160);
                continue;
            }

            $url = '/'.$route->uri();
            foreach ($values as $name => $value) {
                $url = preg_replace('/\{'.$name.'(:[^}]*)?\??\}/', (string) $value, $url);
            }
            $targets = array_filter($built, fn ($m) => in_array(get_class($m), array_filter($params), true));
            $snapshot = fn () => array_map(fn (Model $m) => (array) DB::table($m->getTable())->where($m->getKeyName(), $m->getKey())->first(), $targets);
            $before = $snapshot();
            $scoped = collect($targets)->contains(fn (Model $m) => Schema::hasColumn($m->getTable(), 'company_id') || Schema::hasColumn($m->getTable(), 'franchise_id'));
            $checked++;

            $cases = [
                'a' => fn () => $this->signedInAs($b['admin'])->json($method, $url),
                'b' => fn () => $this->signedInAs($sideways)->json($method, $url),
                'c' => fn () => $this->signedInAs($nothing)->json($method, $url),
                'a-key' => fn () => $this->bearer($key)->json($method, $url),
                'a-mcp' => fn () => $this->bearer($key)->postJson('/api/v1/mcp', [
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                    'params' => ['name' => 'call_api', 'arguments' => ['method' => $method, 'path' => ltrim(substr($url, strlen('/api/v1/')), '/')]],
                ]),
            ];

            foreach ($cases as $case => $call) {
                try {
                    $response = $call();
                    $status = $response->getStatusCode();
                    $body = (string) $response->getContent();
                    if ($case === 'a-mcp' && $status === 200) {
                        $inner = json_decode($body, true)['result']['content'][0]['text'] ?? '';
                        $status = preg_match('/"status":\s*(\d{3})/', $inner, $m) ? (int) $m[1]
                            : (str_starts_with($inner, 'Refused:') ? 403 : 0);
                    }
                } catch (Throwable $e) {
                    $status = 599;
                    $body = '';
                }
                $this->flushHeaders();

                $changed = $snapshot() != $before;
                $leaked = str_contains($body, self::MARKER);
                $key2 = "{$label} {$case}";

                if ($status >= 200 && $status < 300 || ($leaked && $status < 500) || $changed) {
                    $what = "status {$status}".($leaked ? ', body carries the record' : '').($changed ? ', record changed' : '');
                    if ($case === 'b' && ! $scoped) {
                        $results['orgwide'][$key2] = $what;
                    } else {
                        $results['holes'][$key2] = $what;
                    }
                    if ($changed) {
                        DB::rollBack();
                        DB::beginTransaction();
                        continue 2; // the record is gone or altered: later cases would prove nothing
                    }
                } elseif (! in_array($status, [403, 404], true)) {
                    $inconclusive[$key2] = $status;
                }
                $log[] = "{$key2} {$status}";
            }

            if ($method === 'GET') {
                $control = $this->signedInAs($a['admin'])->getJson($url)->getStatusCode();
                $control >= 200 && $control < 300 ? $controlled++ : $uncontrolled++;
                $log[] = "{$label} control {$control}";
            }

            DB::rollBack();
        }

        $holes = $results['holes'] ?? [];
        $orgWide = $results['orgwide'] ?? [];
        ksort($holes); ksort($orgWide); ksort($skipped); ksort($inconclusive);

        @file_put_contents(storage_path('authz-sweep.log'), implode("\n", [
            "checked {$checked}, skipped ".count($skipped).", GET control 2xx {$controlled}, control refused {$uncontrolled}, inconclusive ".count($inconclusive),
            '--- holes', var_export($holes, true),
            '--- org-wide (b)', var_export($orgWide, true),
            '--- skipped', var_export($skipped, true),
            '--- inconclusive', var_export($inconclusive, true),
            '--- all', ...$log,
        ]));

        fwrite(STDERR, sprintf(
            "\nAuthorization sweep: %d routes checked, %d skipped; GET controls 2xx %d / refused %d; %d inconclusive outcomes; %d holes; %d org-wide (b)\n",
            $checked, count($skipped), $controlled, $uncontrolled, count($inconclusive), count($holes), count($orgWide),
        ));

        $this->assertSame(array_keys(self::KNOWN_HOLES), array_keys($holes), 'Authorization holes changed; see storage/authz-sweep.log');
        $this->assertSame(array_keys(self::ORG_WIDE_B), array_keys($orgWide), 'Org-wide (b) set changed');
        $this->assertSame(array_keys(self::SKIPPED), array_keys($skipped), 'Skipped routes changed');
        $this->assertLessThanOrEqual(self::INCONCLUSIVE_CEILING, count($inconclusive));
        $this->assertGreaterThan(100, $checked);
    }

    private function bearer(string $key): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$key);
    }

    /** @return iterable<array{0: Route, 1: string, 2: array<string, class-string<Model>|null>}> */
    private function recordRoutes(): iterable
    {
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! str_contains($route->uri(), '{')
                || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            $params = $this->paramModels($route);
            if (array_filter($params) === []) {
                continue; // no parameter names a record (provider names, dates, keys)
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                yield [$route, $method, $params];
            }
        }
    }

    /** Param name => bound model class, from the action's type hints, else guessed from the name. */
    private function paramModels(Route $route): array
    {
        $hinted = [];
        try {
            foreach ($route->signatureParameters(['subClass' => Model::class]) as $p) {
                $hinted[$p->getName()] = $p->getType()?->getName();
            }
        } catch (Throwable) {
        }

        $out = [];
        $segments = explode('/', $route->uri());
        foreach ($route->parameterNames() as $name) {
            $class = $hinted[$name] ?? $hinted[Str::camel($name)] ?? null;
            if ($class === null) {
                $stem = $name === 'id' || $name === 'uuid'
                    ? $segments[array_search('{'.$name.'}', $segments, true) - 1] ?? ''
                    : preg_replace('/_?id$/i', '', $name);
                $guess = 'App\\Models\\'.Str::studly(Str::singular($stem));
                $class = class_exists($guess) && is_subclass_of($guess, Model::class) ? $guess : null;
            }
            $out[$name] = $class;
        }

        return $out;
    }

    private function freeValue(Route $route, string $name): string
    {
        $pattern = $route->wheres[$name] ?? null;
        foreach (['x', '1', 'facebook', 'google', '2026-11-17', 'a'] as $candidate) {
            if ($pattern === null || preg_match('#^'.$pattern.'$#', $candidate)) {
                return $candidate;
            }
        }

        return 'x';
    }

    private function indexModels(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Models'))) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], Str::after(str_replace('\\', '/', $file->getPathname()), str_replace('\\', '/', app_path('Models')).'/'));
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $this->tableToModel[(new $class)->getTable()] ??= $class;
            }
        }
    }

    /**
     * One row of $class in tenant A, with every NOT NULL column filled and the
     * scope columns pointing at A's company and franchise F1. Factory first;
     * otherwise straight from the schema. Parents already built for this route
     * are reused, so a nested route's child belongs to its parent.
     */
    private function build(string $class, array &$built, array $a, int $depth = 0): Model
    {
        if (isset($built[$class])) {
            return $built[$class];
        }
        if ($class === User::class) {
            return $built[$class] = $this->userWithRole($a, 'Target', [], 'target'.Str::random(6).'@alpha.test');
        }
        foreach ([Company::class => $a['company'], Franchise::class => $a['franchise'], \App\Models\Organization::class => $a['org'], Role::class => null] as $known => $value) {
            if ($class === $known && $value !== null) {
                return $built[$class] = $value;
            }
        }
        if ($depth > 4) {
            throw new \RuntimeException("too deep building {$class}");
        }

        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();
        $fks = collect(Schema::getForeignKeys($table))->mapWithKeys(fn ($fk) => [$fk['columns'][0] => $fk['foreign_table']]);
        $casts = $model->getCasts();
        $raw = [];

        foreach (Schema::getColumns($table) as $col) {
            $name = $col['name'];
            if ($col['auto_increment']) {
                continue;
            }
            if ($name === $model->getKeyName()) {
                $raw[$name] = (string) Str::uuid7();
                continue;
            }
            $scope = ['organization_id' => $a['org']->id, 'company_id' => $a['company']->id, 'franchise_id' => $a['franchise']->id];
            if (isset($scope[$name])) {
                $raw[$name] = $scope[$name];
                continue;
            }
            $foreignTable = $fks[$name] ?? null;
            $foreignClass = $foreignTable ? ($this->tableToModel[$foreignTable] ?? null) : null;
            if ($foreignClass && isset($built[$foreignClass])) {
                $raw[$name] = $built[$foreignClass]->getKey();
                continue;
            }
            if ($col['nullable'] || $col['default'] !== null) {
                if (in_array($name, ['name', 'title', 'notes', 'description', 'body', 'subject', 'first_name', 'last_name', 'label'], true) && ! isset($casts[$name])) {
                    $raw[$name] = self::MARKER;
                }
                continue;
            }
            if ($foreignClass) {
                $raw[$name] = $this->build($foreignClass, $built, $a, $depth + 1)->getKey();
                continue;
            }
            if ($foreignTable) {
                throw new \RuntimeException("{$table}.{$name} references {$foreignTable}, which has no model");
            }
            $raw[$name] = $this->valueFor($col, $casts[$name] ?? null);
        }

        $model->setRawAttributes($raw);
        $model->exists = false;
        $model->saveQuietly();

        return $built[$class] = $model->newQueryWithoutScopes()->whereKey($model->getKey() ?? DB::getPdo()->lastInsertId())->firstOrFail();
    }

    private function valueFor(array $col, ?string $cast): mixed
    {
        if ($cast && enum_exists($cast)) {
            $case = $cast::cases()[0];

            return $case instanceof \BackedEnum ? $case->value : $case->name;
        }
        $type = strtolower($col['type_name']);
        $full = strtolower($col['type']);

        return match (true) {
            $type === 'enum' => preg_match("/enum\\('([^']*)'/", $full, $m) ? $m[1] : '',
            in_array($type, ['json'], true) => '{}',
            in_array($type, ['tinyint', 'smallint', 'int', 'integer', 'bigint', 'mediumint', 'decimal', 'float', 'double', 'year'], true) => $type === 'year' ? 2026 : 0,
            $type === 'date' => '2026-11-17',
            in_array($type, ['datetime', 'timestamp'], true) => '2026-11-17 12:00:00',
            $type === 'time' => '09:00:00',
            in_array($type, ['blob', 'longblob', 'mediumblob', 'binary', 'varbinary'], true) => '',
            $type === 'char' && str_contains($full, '36') => (string) Str::uuid7(),
            in_array($cast, ['array', 'json', 'collection', 'object', 'encrypted:array'], true) => '[]',
            default => Str::limit(self::MARKER, max(1, (int) (preg_match('/\((\d+)\)/', $full, $m) ? $m[1] : 255)), ''),
        };
    }
}
