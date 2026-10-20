<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\CameraSite;
use App\Models\Franchise;
use App\Models\MonitoredDevice;
use App\Models\MonitoringCamera;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Camera sites and pairing a bridge PC to one (camera bridge, phase 1 —
 * docs/superpowers/specs/2026-10-20-camera-bridge-design.md).
 *
 * Permissions reuse MonitoringCameraPolicy: `monitoring.cameras.view` lists,
 * `monitoring.cameras.manage` creates, pairs and disconnects. Sites follow the
 * viewer's current view (CameraSite::visibleTo).
 */
class CameraSitesController extends Controller
{
    /** How long a pairing code lives. */
    private const CODE_TTL_MINUTES = 15;

    /** Wrong codes allowed per person per minute before 429. */
    private const PAIR_ATTEMPTS = 5;

    /** No 0/O, 1/I/L: a code is read off one screen and typed on another. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): array
    {
        $this->authorize('viewAny', MonitoringCamera::class);

        $sites = CameraSite::query()->visibleTo($request->user())
            ->withCount('cameras')->with('device')->orderBy('name')->get();

        return ['data' => $sites->map(fn (CameraSite $s) => $this->present($s))->values()];
    }

    public function show(Request $request, string $id): array
    {
        $this->authorize('viewAny', MonitoringCamera::class);

        $site = $this->visible($request, $id);
        $site->loadCount('cameras')->load('device');

        return ['data' => $this->present($site) + [
            'cameras' => $site->cameras()->orderBy('label')->get()->map(fn (MonitoringCamera $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'enabled' => $c->enabled,
                'supports' => $c->capabilities ?? ['live'],
                'bridge_status' => $c->bridge_status,
                'bridge_reason' => $c->bridge_reason,
                // Stills arrive with the bridge (Task 4); null until the first one.
                'still_url' => null,
            ])->values(),
        ]];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MonitoringCamera::class);

        $data = $this->validated($request);
        $site = CameraSite::create($data + [
            'organization_id' => app(TenantContext::class)->idOrFail(),
            'created_by' => $request->user()->getKey(),
        ]);

        $this->audit->log('monitoring.camera_site.created', $site, [], ['name' => $site->name]);

        return response()->json(['data' => $this->present($site->loadCount('cameras'))], 201);
    }

    public function update(Request $request, string $id): array
    {
        $this->authorize('update', MonitoringCamera::class);

        $site = $this->visible($request, $id);
        $site->update($this->validated($request, partial: true));

        return ['data' => $this->present($site->loadCount('cameras')->load('device'))];
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->authorize('delete', MonitoringCamera::class);

        $site = $this->visible($request, $id);
        $this->audit->log('monitoring.camera_site.deleted', $site, [], ['name' => $site->name]);
        $site->delete(); // its cameras go with it (cascade)

        return response()->noContent();
    }

    /** A one-time code to type on the bridge PC. */
    public function pairingCode(Request $request, string $id): array
    {
        $this->authorize('update', MonitoringCamera::class);

        $site = $this->visible($request, $id);

        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $code = substr($raw, 0, 4).'-'.substr($raw, 4);
        $expires = now()->addMinutes(self::CODE_TTL_MINUTES);

        Cache::put($this->codeKey($code), [
            'site_id' => $site->id,
            'organization_id' => $site->organization_id,
        ], $expires);

        return ['data' => ['code' => $code, 'expires_at' => $expires->toIso8601String()]];
    }

    /**
     * POST /monitoring/bridge/pair — on the bridge PC, by a manager signed in
     * there. Links that PC to the site and returns the site key, once. Only its
     * hash is kept; pairing again replaces it.
     */
    public function pair(Request $request): array
    {
        $this->authorize('update', MonitoringCamera::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'device_key' => ['required', 'string', 'max:64'],
        ]);

        $limiter = 'camera-pair:'.$request->user()->getKey();
        if (RateLimiter::tooManyAttempts($limiter, self::PAIR_ATTEMPTS)) {
            abort(response()->json([
                'message' => 'Too many tries. Make a new code and try again in a minute.',
                'retry_after' => RateLimiter::availableIn($limiter),
            ], 429));
        }

        $claim = Cache::pull($this->codeKey($data['code']));
        $orgId = app(TenantContext::class)->idOrFail();

        if (! is_array($claim) || $claim['organization_id'] !== $orgId) {
            RateLimiter::hit($limiter, 60);
            throw ValidationException::withMessages([
                'code' => 'That code is not valid. It may have been used or expired; make a new one.',
            ]);
        }

        $device = MonitoredDevice::query()->ownedBy($request->user(), $data['device_key'])->first();
        if ($device === null) {
            throw ValidationException::withMessages([
                'device_key' => 'Open EzBizi on this computer and sign in once before pairing it.',
            ]);
        }

        $site = CameraSite::query()->findOrFail($claim['site_id']);
        $key = Str::random(40);
        $site->forceFill([
            'site_key_hash' => hash('sha256', $key),
            'device_id' => $device->id,
            'paired_at' => now(),
        ])->save();

        RateLimiter::clear($limiter);
        $this->audit->log('monitoring.camera_site.paired', $site, [], ['device' => $device->label]);

        return ['data' => ['site_id' => $site->id, 'site_key' => $key]];
    }

    /** Disconnect the bridge PC: its key stops working at once. */
    public function revoke(Request $request, string $id): Response
    {
        $this->authorize('update', MonitoringCamera::class);

        $site = $this->visible($request, $id);
        $site->forceFill(['site_key_hash' => null, 'device_id' => null, 'paired_at' => null])->save();
        $this->audit->log('monitoring.camera_site.revoked', $site);

        return response()->noContent();
    }

    private function visible(Request $request, string $id): CameraSite
    {
        return CameraSite::query()->visibleTo($request->user())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'franchise_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $view = ViewScope::forUser($request->user());

        // A franchise implies its company; and nobody files a site somewhere
        // outside their own view (the same "access is only downwards" rule).
        if (! empty($data['franchise_id'])) {
            $franchise = Franchise::query()->find($data['franchise_id']);
            if ($franchise === null || ! $view->coversFranchise($franchise->id)) {
                throw ValidationException::withMessages(['franchise_id' => 'Pick a branch you can see.']);
            }
            $data['company_id'] = $franchise->company_id;
        } elseif (! empty($data['company_id']) && ! $view->coversCompany($data['company_id'])) {
            throw ValidationException::withMessages(['company_id' => 'Pick a company you can see.']);
        }

        return $data;
    }

    private function codeKey(string $code): string
    {
        return 'camera-pair:'.hash('sha256', strtoupper(str_replace([' ', '-'], '', $code)));
    }

    /** @return array<string, mixed> */
    private function present(CameraSite $site): array
    {
        $online = $site->online();

        return [
            'id' => $site->id,
            'name' => $site->name,
            'company_id' => $site->company_id,
            'franchise_id' => $site->franchise_id,
            'online' => $online,
            'paired' => $site->site_key_hash !== null,
            'last_checkin_at' => $site->last_checkin_at?->toIso8601String(),
            // When it went quiet, and the bridge's own last word on why.
            'offline_since' => $online ? null : $site->last_checkin_at?->toIso8601String(),
            'last_reason' => $site->status['reason'] ?? null,
            'device' => $site->device ? [
                'id' => $site->device->id,
                'pc_name' => $site->device->specs['computer_name'] ?? $site->device->label,
                'app_version' => $site->status['app_version'] ?? null,
            ] : null,
            'camera_count' => (int) ($site->cameras_count ?? 0),
        ];
    }
}
