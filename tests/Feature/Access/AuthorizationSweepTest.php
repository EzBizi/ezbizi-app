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
     * "METHOD uri case" => what was measured. Unscoped case (b) results go to
     * ORG_WIDE_B unless the desk ruled them real, in which case they are here.
     */
    private const KNOWN_HOLES = [
        // Cases a, a-key, a-mcp and c: none measured 2026-11-17.
        // InspectionReportPolicy::view (app/Policies/InspectionReportPolicy.php:37-47) checks permission and
        // organization only, no reach: a sibling company opens a report's photographs through the attachment.
        'GET api/v1/attachments/{attachment} b type=inspection_reports' => 'status 200',
    ];

    /** Case (b) reaching a record that has no company/franchise/scope column; each carries its ruling, from the desk. */
    private const ORG_WIDE_B = [
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/ai-assistance/manuals/{manual} b' => 'status 204',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/ai-assistance/parts/{part} b' => 'status 204',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/ai-assistance/staff-bot/items/{item} b' => 'status 204',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/ai-assistance/staff-bot/menus/{menu} b' => 'status 204',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/ai-assistance/whatsapp-groups/{group} b' => 'status 204',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/hr/time-clock/reports/{report} b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/inspections/codes/{inspection_code} b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/inspections/{inspection_definition} b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/leads/hidden-numbers/{number} b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/phone/reports/{report} b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'DELETE api/v1/task-board-folders/{folder} b' => 'status 204',
        // Desk: by design, build plan §5.2 [DECIDED] one person is one customer, readable org-wide
        'GET api/v1/customers/{customer} b' => 'status 200, body carries the record',
        // Desk: by design, build plan §5.2 [DECIDED] one person is one customer, readable org-wide
        'GET api/v1/customers/{customer}/export b' => 'status 200, body carries the record',
        // Desk: by design, build plan §5.2 [DECIDED] one person is one customer, readable org-wide
        'GET api/v1/customers/{customer}/workiz-notes b' => 'status 200',
        // Desk: by design, organization-owned contract TEMPLATES (ContractPolicy::mayAuthor)
        'GET api/v1/hr/contracts/{contract} b' => 'status 200, body carries the record',
        // Desk: by design, organization-owned contract TEMPLATES (ContractPolicy::mayAuthor)
        'GET api/v1/hr/contracts/{contract}/versions b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'GET api/v1/hr/time-clock/reports/{report}/preview b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'GET api/v1/inspections/codes/{inspection_code} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'GET api/v1/inspections/{inspection_definition} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'GET api/v1/inspections/{inspection_definition}/cover b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'PATCH api/v1/ai-assistance/parts/{part} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PATCH api/v1/ai-assistance/whatsapp-groups/{group} b' => 'status 200, body carries the record',
        // Desk: by design, build plan §5.2 [DECIDED] one person is one customer, readable org-wide
        'PATCH api/v1/customers/{customer} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PATCH api/v1/hr/time-clock/reports/{report} b' => 'status 200',
        // By design (desk ruling 2026-11-17)
        'PATCH api/v1/inspections/codes/{inspection_code} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PATCH api/v1/memberships/plans/{plan} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PATCH api/v1/task-board-folders/{folder} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'POST api/v1/ai-assistance/whatsapp-groups/{group}/publish b' => 'status 422, body carries the record',
        // By design (desk ruling 2026-11-17)
        'POST api/v1/inspections/{inspection_definition}/publish b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'POST api/v1/inspections/{inspection_definition}/rehearse b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'POST api/v1/inspections/{inspection_definition}/unpublish b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PUT api/v1/ai-assistance/parts/{part} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PUT api/v1/ai-assistance/staff-bot/items/{item} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PUT api/v1/ai-assistance/staff-bot/menus/{menu} b' => 'status 200, body carries the record',
        // By design (desk ruling 2026-11-17)
        'PUT api/v1/ai-assistance/whatsapp-groups/{group} b' => 'status 200, body carries the record',
        // Desk: by design, build plan §5.2 [DECIDED] one person is one customer, readable org-wide
        'PUT api/v1/customers/{customer} b' => 'status 200, body carries the record',
    ];

    /** Attachment types whose files a sibling company may open by design. */
    private const ATTACHMENT_TYPES_BY_DESIGN = [
        'customers' => 'attachments follow their customer; customers are org-wide per build plan §5.2',
    ];

    /** Attachable types no subject record could be built for. Can only shrink. */
    private const ATTACHMENT_TYPES_UNBUILT = [
    ];

    /** Routes no record could be built for. Can only shrink. */
    private const SKIPPED = [
    ];

    /**
     * GET routes where org A's own Administrator does not get 2xx either, so the
     * refusals measured there prove nothing. Can only shrink.
     */
    private const CONTROL_UNPROVEN = [
        'GET api/v1/email/mailboxes/{mailbox}/folders' => '500: mailbox needs a real provider driver; builder writes a probe string',
        'GET api/v1/email/mailboxes/{mailbox}/messages' => '500: mailbox needs a real provider driver; builder writes a probe string',
        'GET api/v1/email/mailboxes/{mailbox}/messages/{message}' => '500: mailbox needs a real provider driver; builder writes a probe string',
        'GET api/v1/email/mailboxes/{mailbox}/messages/{message}/picture' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/email/mailboxes/{mailbox}/messages/{message}/file' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/suggestions/{suggestion}/files/{index}' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/ekomi/{connection}/overview' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/ekomi/{connection}/requests' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/business-profile/locations/{location}/search-terms' => '502: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/business-profile/locations/{location}/media' => '409: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/business-profile/locations/{location}/services' => '409: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/leads/{lead}/conversations/{conversation}/recording' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/leads/{lead}/workiz-choices' => '422: needs a specific record state or query value',
        'GET api/v1/leads/{lead}/booking/days' => '404: needs a child row ("No query results for model [App\Models\WebBooking) the builder does not create',
        'GET api/v1/financials/views/{view}/split' => '422: needs a specific record state or query value',
        'GET api/v1/web/sites/{site}/seo-helper' => '502: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/web/sites/{site}/seo-helper/{page}' => '502: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/web/sites/{site}/content' => '422: needs a specific record state or query value',
        'GET api/v1/web/sites/{site}/content/options' => '502: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/social/workiz-web/{connection}/accounts' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/field/recordings/{recording}/audio/{seq}' => '404: needs a child row ("No query results for model [App\Models\FieldRecordingChunk) the builder does not create',
        'GET api/v1/branding/companies/{company}' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/hr/contracts/{contract}/draft.pdf' => '404: needs a signed / published document the generic builder does not create',
        'GET api/v1/hr/onboardings/{onboarding}/document/pdf' => '404: needs a signed / published document the generic builder does not create',
        'GET api/v1/hr/onboardings/{onboarding}/answers/{field}' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/social/posts/{post}/media' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/social/connections/{connection}/instagram-comments' => '422: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/phone/calls/{call}/recording' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/email/workspaces/{connection}/people' => '422: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/email/workspaces/{connection}/people/{address}/signature' => '404: needs a live provider (Google/WordPress/Meta) the test cannot reach',
        'GET api/v1/users/{user}/avatar' => '404: abort(404) in the action, file or provider state the builder does not create',
        'GET api/v1/staff/suggestions/{suggestion}/files/{index}' => '403: EzBizi staff-only endpoint; no tenant admin can open it',
    ];

        /** Upper bound for INCONCLUSIVE outcomes; lower it when it falls. */
    private const INCONCLUSIVE_CEILING = 25; // measured 2026-11-17, after minimal bodies

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
        foreach (array_keys(config('filesystems.disks')) as $disk) {
            \Illuminate\Support\Facades\Storage::fake($disk);
        }

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
        $controlFailures = [];
        $controlled = 0;
        $uncontrolled = 0;
        $log = [];

        foreach ($this->recordRoutes() as [$route, $method, $params]) {
            $label = $method.' '.$route->uri();
            if (($only = getenv('AUTHZ_ONLY')) && ! preg_match('#'.$only.'#', $label)) {
                continue; // diagnosis only: the assertions below fail under a filter
            }

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
            $scoped = collect($targets)->contains(fn (Model $m) => Schema::hasColumn($m->getTable(), 'company_id') || Schema::hasColumn($m->getTable(), 'franchise_id') || Schema::hasColumn($m->getTable(), 'scope_type'));
            $checked++;

            $payload = $this->minimalBody($method, $url, $a['admin'], $targets);

            $cases = [
                'a' => fn () => $this->signedInAs($b['admin'])->json($method, $url, $payload),
                'b' => fn () => $this->signedInAs($sideways)->json($method, $url, $payload),
                'c' => fn () => $this->signedInAs($nothing)->json($method, $url, $payload),
                'a-key' => fn () => $this->bearer($key)->json($method, $url, $payload),
                'a-mcp' => fn () => $this->bearer($key)->postJson('/api/v1/mcp', [
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                    'params' => ['name' => 'call_api', 'arguments' => ['method' => $method, 'path' => ltrim(substr($url, strlen('/api/v1/')), '/')] + ($payload === [] ? [] : ($method === 'GET' ? ['query' => $payload] : ['body' => $payload]))],
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
                        break; // the record is gone or altered: later cases would prove nothing
                    }
                } elseif (! in_array($status, [403, 404], true)) {
                    $inconclusive[$key2] = $status;
                }
                $log[] = "{$key2} {$status}";
            }

            if ($method === 'GET') {
                $res = $this->signedInAs($a['admin'])->json('GET', $url, $payload);
                $control = $res->getStatusCode();
                if ($control >= 200 && $control < 300) {
                    $controlled++;
                } else {
                    $uncontrolled++;
                    $controlFailures[$label] = $control;
                }
                $log[] = "{$label} control {$control} ".Str::limit(rescue(fn () => json_encode([$res->json('message'), $res->json('exception'), $res->json('file'), $res->json('line'), array_slice((array) $res->json('trace'), 0, 8)]), get_class($res->baseResponse), false), 2500);
            }

            DB::rollBack();
        }

        $holes = $results['holes'] ?? [];
        $orgWide = $results['orgwide'] ?? [];
        // The desk ruled some unscoped (b) results to be real holes: they live in KNOWN_HOLES.
        foreach (array_intersect_key($orgWide, self::KNOWN_HOLES) as $k => $v) {
            $holes[$k] = $v;
            unset($orgWide[$k]);
        }
        ksort($holes); ksort($orgWide); ksort($skipped); ksort($inconclusive);

        @file_put_contents(storage_path('authz-sweep.log'), implode("\n", [
            "checked {$checked}, skipped ".count($skipped).", GET control 2xx {$controlled}, control refused {$uncontrolled}, inconclusive ".count($inconclusive),
            '--- holes', var_export($holes, true),
            '--- org-wide (b)', var_export($orgWide, true),
            '--- skipped', var_export($skipped, true),
            '--- inconclusive', var_export($inconclusive, true),
            '--- control failures', var_export($controlFailures, true),
            '--- all', ...$log,
        ]));

        fwrite(STDERR, sprintf(
            "\nAuthorization sweep: %d routes checked, %d skipped; GET controls 2xx %d / refused %d; %d inconclusive outcomes; %d holes; %d org-wide (b)\n",
            $checked, count($skipped), $controlled, $uncontrolled, count($inconclusive), count($holes), count($orgWide),
        ));

        $this->assertSame($this->sortedKeys(array_filter(self::KNOWN_HOLES, fn ($k) => ! str_contains($k, ' type='), ARRAY_FILTER_USE_KEY)), $this->sortedKeys($holes), 'Authorization holes changed; see storage/authz-sweep.log');
        $this->assertSame($this->sortedKeys(self::ORG_WIDE_B), $this->sortedKeys($orgWide), 'Org-wide (b) set changed');
        $this->assertSame($this->sortedKeys(self::SKIPPED), $this->sortedKeys($skipped), 'Skipped routes changed');
        ksort($controlFailures);
        $this->assertSame($this->sortedKeys(self::CONTROL_UNPROVEN), $this->sortedKeys($controlFailures), 'GET routes whose own-org Administrator control is not 2xx changed; see storage/authz-sweep.log');
        $this->assertLessThanOrEqual(self::INCONCLUSIVE_CEILING, count($inconclusive));
        $this->assertGreaterThan(100, $checked);
    }

    /**
     * The smallest body that gets past validation, learned from the target
     * org's own Administrator: send, read the 422's field errors, fill each
     * field (the record's own value of that name first, else a value shaped by
     * the error message), up to five rounds. Done inside a savepoint so the
     * admin's request never changes what the refused callers meet.
     */
    private function minimalBody(string $method, string $url, User $admin, array $targets): array
    {
        $payload = [];
        DB::beginTransaction();
        try {
            for ($round = 0; $round < 5; $round++) {
                $res = $this->signedInAs($admin)->json($method, $url, $payload);
                $errors = $res->getStatusCode() === 422 ? ($res->json('errors') ?? []) : [];
                if (! is_array($errors) || $errors === []) {
                    break;
                }
                foreach ($errors as $field => $messages) {
                    $message = strtolower(implode(' ', (array) $messages));
                    $own = collect($targets)->map(fn (Model $m) => $m->getAttributes()[$field] ?? null)->filter(fn ($v) => $v !== null && ! str_contains((string) $v, self::MARKER))->first();
                    $current = data_get($payload, $field);
                    data_set($payload, $field, match (true) {
                        $own !== null && $current === null => $own,
                        str_contains($message, 'true or false') => true,
                        str_contains($message, 'array') || str_contains($message, 'list') => is_array($current) ? [] : ['x'],
                        str_contains($message, 'integer') || str_contains($message, 'number') || str_contains($message, 'numeric') => 1,
                        str_contains($message, 'email') => 'probe@example.test',
                        str_contains($message, 'uuid') => (string) Str::uuid7(),
                        str_contains($message, 'url') => 'https://example.test',
                        str_contains($message, 'time') && ! str_contains($message, 'date') => '09:00',
                        str_contains($message, 'date') => '2026-11-17',
                        str_contains($message, 'invalid') && $current === 'x' => 'a',
                        default => $current === null ? 'x' : $current,
                    });
                }
            }
        } catch (Throwable) {
        } finally {
            DB::rollBack();
            $this->flushHeaders();
        }

        return $payload;
    }

    /**
     * Desk 2026-11-17: an attachment is only as org-wide as what it hangs off.
     * One attachment per attachable type in the morph map, hung off a record in
     * company A / franchise F1; the sibling-company user (every permission,
     * reach only at the sibling franchise) tries to open and delete it.
     */
    public function test_attachments_by_attachable_type_for_a_sibling_company(): void
    {
        ini_set('memory_limit', '2G');
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
        Queue::fake();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        foreach (array_keys(config('filesystems.disks')) as $disk) {
            \Illuminate\Support\Facades\Storage::fake($disk);
        }

        $a = $this->provisionTenant('Alpha');
        $siblingCompany = $this->inTenant($a['org'], fn () => Company::create(['organization_id' => $a['org']->id, 'name' => 'Alpha Sibling Co']));
        $siblingFranchise = $this->inTenant($a['org'], fn () => Franchise::create(['organization_id' => $a['org']->id, 'company_id' => $siblingCompany->id, 'name' => 'Alpha Sibling F']));
        $sideways = $this->userWithRole($a, 'Everything Elsewhere', app(PermissionCatalogue::class)->keys(), 'sideways@alpha.test', $siblingFranchise);
        $this->indexModels();

        $table = [];
        $leaks = [];
        $unbuilt = [];
        foreach (\Illuminate\Database\Eloquent\Relations\Relation::morphMap() as $type => $class) {
            DB::beginTransaction();
            try {
                $built = [];
                $subject = $this->inTenant($a['org'], fn () => $this->build($class, $built, $a));
                $attachment = $this->inTenant($a['org'], fn () => $this->build(\App\Models\Attachment::class, $built, $a));
                DB::table('attachments')->where('id', $attachment->id)->update(['attachable_type' => $type, 'attachable_id' => $subject->getKey()]);
            } catch (Throwable $e) {
                DB::rollBack();
                $unbuilt[$type] = Str::limit(class_basename($e).': '.$e->getMessage(), 120);
                $table[] = sprintf('%-28s unbuilt: %s', $type, $unbuilt[$type]);
                continue;
            }

            $url = '/api/v1/attachments/'.$attachment->id;
            $control = $this->signedInAs($a['admin'])->getJson($url)->getStatusCode();
            $get = $this->signedInAs($sideways)->getJson($url);
            $getStatus = $get->getStatusCode();
            $getLeak = ($getStatus >= 200 && $getStatus < 300) || ($getStatus < 500 && str_contains(rescue(fn () => (string) $get->getContent(), '', false), self::MARKER));
            $delete = $this->signedInAs($sideways)->deleteJson($url)->getStatusCode();
            $deleteLeak = ($delete >= 200 && $delete < 300) || ! DB::table('attachments')->where('id', $attachment->id)->exists();

            if ($getLeak) {
                $leaks["GET api/v1/attachments/{attachment} b type={$type}"] = "status {$getStatus}";
            }
            if ($deleteLeak) {
                $leaks["DELETE api/v1/attachments/{attachment} b type={$type}"] = "status {$delete}";
            }
            $table[] = sprintf('%-28s control GET %d | sibling GET %d%s | sibling DELETE %d%s', $type, $control, $getStatus, $getLeak ? ' LEAK' : '', $delete, $deleteLeak ? ' LEAK' : '');
            DB::rollBack();
        }

        @file_put_contents(storage_path('authz-attachments.log'), implode("\n", $table)."\n".var_export($leaks, true));
        fwrite(STDERR, "\nAttachments by type (sibling company):\n".implode("\n", $table)."\n");

        $byDesign = array_filter($leaks, fn ($k) => isset(self::ATTACHMENT_TYPES_BY_DESIGN[Str::after($k, 'type=')]), ARRAY_FILTER_USE_KEY);
        $this->assertSame(
            $this->sortedKeys(array_filter(self::KNOWN_HOLES, fn ($k) => str_contains($k, ' type='), ARRAY_FILTER_USE_KEY)),
            $this->sortedKeys(array_diff_key($leaks, $byDesign)),
            'Attachment types readable or deletable from a sibling company changed; see storage/authz-attachments.log',
        );
        $this->assertSame($this->sortedKeys(self::ATTACHMENT_TYPES_UNBUILT), $this->sortedKeys($unbuilt), 'Unbuildable attachable types changed');
    }

    private function sortedKeys(array $list): array
    {
        $keys = array_keys($list);
        sort($keys);

        return $keys;
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
        if ($name === 'kind' && str_contains($route->uri(), 'web/sites/')) {
            return \App\Models\WebItem::KINDS[0];
        }
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
            return $built[$class] = $this->userWithRole($a, 'Target'.Str::random(6), [], 'target'.Str::random(6).'@alpha.test');
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
            $scope = ['organization_id' => $a['org']->id, 'company_id' => $a['company']->id, 'franchise_id' => $a['franchise']->id, 'assigned_franchise_id' => $a['franchise']->id];
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
            if (str_ends_with($name, '_type') && strtolower($col['type_name']) === 'enum' && Schema::hasColumn($table, Str::beforeLast($name, '_type').'_id')) {
                // scope_type enum('organization','company','franchise'): the franchise, so reach applies
                if (str_contains($col['type'], "'franchise'")) {
                    $raw[$name] = 'franchise';
                    $raw[Str::beforeLast($name, '_type').'_id'] = $a['franchise']->id;
                    continue;
                }
            } elseif (str_ends_with($name, '_type') && Schema::hasColumn($table, Str::beforeLast($name, '_type').'_id')) {
                // A real morph-map entry (jobs: scoped), so the generic attachment is one the app can serve.
                $morphMap = \Illuminate\Database\Eloquent\Relations\Relation::morphMap();
                $morph = array_key_first($morphMap);
                $raw[$name] = $morph;
                $raw[Str::beforeLast($name, '_type').'_id'] = $this->build($morphMap[$morph], $built, $a, $depth + 1)->getKey();
                continue;
            }
            if (array_key_exists($name, $raw)) {
                continue;
            }
            if ($name === 'disk' || str_ends_with($name, '_disk')) {
                $raw[$name] = config('filesystems.default');
                continue;
            }
            if (in_array($name, ['path', 'file_path', 'storage_path', 'image_path', 'pdf_path', 'cover_path', 'avatar_path', 'media_path', 'audio_path'], true)) {
                $raw[$name] = 'authz/'.Str::random(8).'.png';
                foreach (array_keys(config('filesystems.disks')) as $disk) {
                    \Illuminate\Support\Facades\Storage::disk($disk)->put($raw[$name], base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
                }
                continue;
            }
            if ($col['nullable'] || $col['default'] !== null) {
                if (in_array($name, ['name', 'title', 'notes', 'description', 'body', 'subject', 'first_name', 'last_name', 'label'], true) && ! isset($casts[$name])) {
                    $raw[$name] = self::MARKER.Str::lower(Str::random(6));
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

        if (getenv("AUTHZ_DEBUG")) { fwrite(STDERR, $class." ".json_encode($raw)."
"); }
        // What the generic builder cannot guess: the owner a policy insists on.
        $raw = array_merge($raw, [
            \App\Models\TaskBoard::class => ['kind' => 'franchise'],
            \App\Models\InspectionRun::class => ['user_id' => $a['admin']->id],
        ][$class] ?? []);
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
            default => Str::limit(self::MARKER.Str::lower(Str::random(6)), max(1, (int) (preg_match('/\((\d+)\)/', $full, $m) ? $m[1] : 255)), ''),
        };
    }
}
