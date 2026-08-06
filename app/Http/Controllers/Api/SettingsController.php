<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings over the API (plan §6, §11.4).
 *
 * The first real exercise of the REST contract, so it sets the pattern the rest
 * of the API follows: resolve the scope, check the permission, check the reach,
 * act, audit.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly SettingsResolver $resolver,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/settings?scope_type=franchise&scope_id={uuid}
     *
     * Returns effective values WITH their source, because "what is this set to"
     * and "where did that come from" are different questions and an admin needs
     * both. Without the source they change the wrong level and wonder why
     * nothing happened.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);
        $scopeType = $this->scopeTypeOf($scope);

        $settings = [];

        foreach ($this->registry->all() as $key => $definition) {
            $source = $this->resolver->sourceOf($key, $scope);

            $settings[] = [
                'key'            => $key,
                'label'          => $definition->label,
                'type'           => $definition->type,
                'value'          => $definition->isSensitive ? null : $this->resolver->get($key, $scope),
                'is_sensitive'   => $definition->isSensitive,
                'options'        => $definition->options ?: null,
                'help'           => $definition->help,
                // Can this SCOPE hold it? Inheritance, not permission.
                'editable_here'  => $definition->allowsScope($scopeType),

                // Can this PERSON set it here? Two separate questions, and the
                // client had only the first — so somebody without
                // `organizations.edit` saw every control live, changed one, and
                // got a 403 from a switch that had already animated.
                'may_edit'       => $definition->allowsScope($scopeType)
                                    && $request->user()->hasPermission($definition->permissionRequired()),

                // Named so a refusal can say which one is missing rather than
                // "you cannot do that".
                'permission'     => $definition->permissionRequired(),
                'allowed_scopes' => $definition->allowedScopes,
                'source'         => $source === null ? null : [
                    'type'      => $this->scopeTypeOf($source),
                    'id'        => $source->getKey(),
                    'name'      => $source->name,
                    'inherited' => ! $source->is($scope),
                ],
            ];
        }

        return response()->json([
            'data' => $settings,
            'meta' => ['scope_type' => $scopeType, 'scope_id' => $scope->getKey()],
        ]);
    }

    /** PATCH /api/v1/settings/{key} */
    public function update(Request $request, string $key): JsonResponse
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            return response()->json([
                'message' => "Unknown setting '{$key}'.",
                'code'    => 'unknown_setting',
            ], Response::HTTP_NOT_FOUND);
        }

        $scope = $this->resolveScope($request);
        $this->authorizeEdit($request, $definition, $scope);

        $request->validate(['value' => ['present']]);

        try {
            $setting = DB::transaction(function () use ($key, $scope, $request, $definition) {
                $before = $this->resolver->get($key, $scope);
                $saved  = $this->resolver->set($key, $scope, $request->input('value'));

                // Inside the transaction — the change and its record commit
                // together or not at all (§10, R-11).
                $this->audit->log(
                    action: 'settings.updated',
                    target: $scope,
                    changes: [
                        'key'    => $key,
                        'before' => $definition->isSensitive ? '[redacted]' : $before,
                        'after'  => $definition->isSensitive ? '[redacted]' : $this->resolver->get($key, $scope),
                    ],
                );

                return $saved;
            });
        } catch (InvalidArgumentException $e) {
            // Definition-level validation: bad enum value, wrong scope, wrong type.
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }

        return response()->json([
            'data' => [
                'key'   => $key,
                'value' => $definition->isSensitive ? null : $this->resolver->get($key, $scope),
                'scope' => ['type' => $this->scopeTypeOf($scope), 'id' => $scope->getKey()],
            ],
        ]);
    }

    /**
     * DELETE /api/v1/settings/{key} — drop the override, inherit again.
     *
     * Distinct from setting the parent's current value, which would freeze
     * today's value as an explicit choice and stop tracking the parent.
     */
    public function destroy(Request $request, string $key): JsonResponse
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            return response()->json([
                'message' => "Unknown setting '{$key}'.",
                'code'    => 'unknown_setting',
            ], Response::HTTP_NOT_FOUND);
        }

        $scope = $this->resolveScope($request);
        $this->authorizeEdit($request, $definition, $scope);

        DB::transaction(function () use ($key, $scope) {
            $this->resolver->forget($key, $scope);
            $this->audit->log('settings.reset', $scope, ['key' => $key]);
        });

        return response()->json([
            'data' => [
                'key'             => $key,
                'value'           => $this->resolver->get($key, $scope),
                'now_inherited'   => true,
            ],
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * Resolve the scope from the request.
     *
     * Every lookup is tenant-scoped, so a scope_id belonging to another
     * organization simply isn't found — the caller cannot reach across the
     * boundary by guessing an id.
     */
    private function resolveScope(Request $request): Model
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:organization,company,franchise'],
            'scope_id'   => ['required', 'uuid'],
        ]);

        $model = match ($validated['scope_type']) {
            'organization' => $this->tenant->get()?->getKey() === $validated['scope_id']
                ? $this->tenant->get()
                : null,
            'company'      => Company::find($validated['scope_id']),
            'franchise'    => Franchise::find($validated['scope_id']),
        };

        abort_if($model === null, Response::HTTP_NOT_FOUND, 'Scope not found.');

        return $model;
    }

    /**
     * Two checks, both required:
     *
     *   1. Does the role grant the permission? (what)
     *   2. Can the user reach this scope?      (where)
     *
     * A permission without reach lets a New Jersey manager edit Texas's tax
     * rate; reach without permission lets anyone who can see a franchise
     * reconfigure it.
     */
    private function authorizeEdit(Request $request, SettingDefinition $definition, Model $scope): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission($definition->permissionRequired()),
            Response::HTTP_FORBIDDEN,
            "Missing permission: {$definition->permissionRequired()}."
        );

        if ($scope instanceof Franchise) {
            abort_unless(
                $user->canActOnFranchise($scope),
                Response::HTTP_FORBIDDEN,
                'That franchise is outside your assignments.'
            );
        }

        if ($scope instanceof Company) {
            $reachable = $user->reachableFranchises()
                ->pluck('company_id')
                ->merge($user->reachableFranchises()->map(fn ($f) => $f->company?->parent_company_id))
                ->filter()
                ->contains($scope->getKey());

            abort_unless($reachable, Response::HTTP_FORBIDDEN, 'That company is outside your assignments.');
        }
    }

    private function scopeTypeOf(Model $scope): string
    {
        return match (true) {
            $scope instanceof Organization => 'organization',
            $scope instanceof Company      => 'company',
            $scope instanceof Franchise    => 'franchise',
        };
    }
}
