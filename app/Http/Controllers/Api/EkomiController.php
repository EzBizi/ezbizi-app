<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\Review;
use App\Models\SocialConnection;
use App\Services\Reviews\EkomiAccount;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The eKomi screen (Jose, 2026-11-02): each eKomi account's standing, the
 * review requests it sent and how far they got, asking a job's customer, and
 * the account's settings. Reading takes Reviews' view; asking, sending and
 * settings take Reviews' publish, the grant for what goes out in the
 * business's name.
 */
class EkomiController extends Controller
{
    /** GET /ekomi: the organization's eKomi accounts. */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Review::class);

        return response()->json(['data' => SocialConnection::query()->where('provider', SocialProvider::Ekomi->value)->orderBy('display_name')->get()
            ->map(fn (SocialConnection $c) => ['id' => $c->id, 'name' => $c->display_name, 'shop_id' => $c->remote_id, 'status' => $c->status,
                'last_sync' => $c->last_reached_at?->toIso8601String(), 'settings' => self::settings($c),
                'reviews' => Review::query()->where('connection_id', $c->id)->count()])->values()->all()]);
    }

    /** GET /ekomi/{connection}/overview?from&to: the account and the request funnel, the last 30 days by default. */
    public function overview(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->authorize('viewAny', Review::class);
        $this->ekomi($connection);
        [$from, $to] = $this->range($request);

        return $this->live(fn () => ['from' => $from, 'to' => $to, 'snapshot' => EkomiAccount::snapshot($connection),
            'funnel' => EkomiAccount::funnel($connection, $from, $to)]);
    }

    /** GET /ekomi/{connection}/requests?from&to&status&channel&q: who was asked. */
    public function requests(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->authorize('viewAny', Review::class);
        $in = $request->validate(['status' => ['nullable', 'in:not_started,started,completed,interrupted'],
            'channel' => ['nullable', 'in:email,sms,telephone'], 'q' => ['nullable', 'string', 'max:100']]);
        $this->ekomi($connection);
        [$from, $to] = $this->range($request);

        return $this->live(function () use ($connection, $in, $from, $to) {
            $rows = EkomiAccount::requests($connection, array_filter(['start_date' => $from, 'end_date' => $to,
                'workflow_status' => $in['status'] ?? null, 'recipient_type' => $in['channel'] ?? null]));
            $q = Str::lower(trim((string) ($in['q'] ?? '')));

            return $q === '' ? $rows : array_values(array_filter($rows,
                fn ($r) => str_contains(Str::lower($r['name'].' '.$r['email'].' '.$r['order_id']), $q)));
        });
    }

    /** POST /ekomi/{connection}/requests {job_id}: asks that job's customer for a review. */
    public function ask(Request $request, SocialConnection $connection, AuditLogger $audit): JsonResponse
    {
        $this->mayPublish($request);
        $this->ekomi($connection);
        $job = Job::query()->with('customer')->findOrFail($request->validate(['job_id' => ['required', 'uuid']])['job_id']);
        $this->authorize('view', $job);
        $email = trim((string) $job->customer?->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'This customer has no email address to ask.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        [$first, $last] = array_pad(explode(' ', trim((string) $job->customer->name), 2), 2, '');

        return $this->live(function () use ($connection, $job, $email, $first, $last, $audit) {
            $result = EkomiAccount::ask($connection, (string) $job->id, $email, $first, $last);
            $audit->log($result['ok'] ? 'ekomi.review_requested' : 'ekomi.review_request_refused', $job, metadata: ['message' => $result['message']]);

            return $result;
        });
    }

    /** PUT /ekomi/{connection}/settings {shop_id?, password?, auto_request?, delay_hours?, start_date?}: a blank password keeps the old one. */
    public function updateSettings(Request $request, SocialConnection $connection, AuditLogger $audit): JsonResponse
    {
        $this->mayPublish($request);
        $in = $request->validate(['shop_id' => ['nullable', 'string', 'max:40'], 'password' => ['nullable', 'string', 'max:200'],
            'auto_request' => ['sometimes', 'boolean'], 'delay_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $creds = (array) ($this->ekomi($connection)->credentials ?? []);
        foreach (['shop_id', 'password'] as $k) {
            if (($in[$k] ?? '') !== '') {
                $creds[$k] = trim($in[$k]);
            }
        }
        $creds = array_merge($creds, array_intersect_key($in, array_flip(['auto_request', 'delay_hours', 'start_date'])));
        // Switched on with no start: only jobs completed from today, never the backlog.
        if (($creds['auto_request'] ?? false) && empty($creds['start_date'])) {
            $creds['start_date'] = now()->toDateString();
        }
        $connection->forceFill(['credentials' => $creds, 'remote_id' => $creds['shop_id'] ?? $connection->remote_id])->save();
        $audit->log('ekomi.settings_changed', $connection, metadata: ['fields' => array_keys(array_diff_key($in, ['password' => 1]))]);

        return response()->json(['data' => self::settings($connection)]);
    }

    /** POST /ekomi/{connection}/test: does eKomi accept the shop id and password. */
    public function test(SocialConnection $connection): JsonResponse
    {
        $this->authorize('viewAny', Review::class);
        $this->ekomi($connection);

        return $this->live(fn () => ['ok' => true, 'snapshot' => EkomiAccount::snapshot($connection)]);
    }

    /** @return array{auto_request: bool, delay_hours: int, start_date: ?string} */
    public static function settings(SocialConnection $c): array
    {
        $creds = (array) ($c->credentials ?? []);

        return ['auto_request' => (bool) ($creds['auto_request'] ?? false), 'delay_hours' => (int) ($creds['delay_hours'] ?? 24),
            'start_date' => $creds['start_date'] ?? null];
    }

    private function mayPublish(Request $request): void
    {
        $this->authorize('viewAny', Review::class);
        abort_unless($request->user()->hasPermission('reviews.publish'), 403,
            Refusal::permission('send review requests or change eKomi')->message());
    }

    private function ekomi(SocialConnection $connection): SocialConnection
    {
        abort_unless($connection->provider === SocialProvider::Ekomi->value, 404);

        return $connection;
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $in = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return [$in['from'] ?? now()->subDays(30)->toDateString(), $in['to'] ?? now()->toDateString()];
    }

    private function live(callable $read): JsonResponse
    {
        try {
            return response()->json(['data' => $read()]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'eKomi did not answer just now. Try again later.'], Response::HTTP_BAD_GATEWAY);
        }
    }
}
