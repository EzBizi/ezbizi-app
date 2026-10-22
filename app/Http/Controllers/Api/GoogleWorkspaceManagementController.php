<?php

namespace App\Http\Controllers\Api;

use App\Models\GoogleWorkspaceConnection;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Email\GoogleWorkspace;
use App\Support\Email\GoogleWorkspaceRefused;
use App\Support\Email\WorkspaceSignatures;
use App\Support\Access\Refusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Email → Google Workspace (Jose, 2026-10-22): each connected Workspace as a
 * card; inside it, its people, which EzBizi person each address is, and their
 * Gmail signatures -- one at a time or in bulk, filled from EzBizi.
 *
 * Who may: whoever may manage Workspace on Email settings (`email.edit`), for
 * the Workspaces their current view shows -- the same gate and the same list
 * as GoogleWorkspaceAuthController.
 */
class GoogleWorkspaceManagementController extends Controller
{
    /** One request writes at most this many signatures. */
    private const MAX_APPLY = 200;

    public function __construct(
        private readonly GoogleWorkspace $workspace,
        private readonly WorkspaceSignatures $signatures,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/email/workspaces */
    public function index(Request $request): JsonResponse
    {
        $this->assertMayManage($request);

        return response()->json(['data' => $this->workspace->connectionsVisibleTo($request->user())
            ->map(function (GoogleWorkspaceConnection $c) {
                try {
                    $count = count($this->signatures->people($c));
                } catch (GoogleWorkspaceRefused) {
                    $count = null;   // the directory could not be read just now
                }

                return [...$this->card($c), 'people_count' => $count];
            })
            ->values()]);
    }

    /** GET /api/v1/email/workspaces/{connection}/people */
    public function people(Request $request, GoogleWorkspaceConnection $connection): JsonResponse
    {
        $this->assertMayManage($request, $connection);

        try {
            $people = $this->signatures->people($connection);
        } catch (GoogleWorkspaceRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            ...$this->card($connection),
            'template'     => $connection->signature_template,
            'placeholders' => WorkspaceSignatures::PLACEHOLDERS,
            'people'       => $people,
            'candidates'   => User::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'email'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
        ]]);
    }

    /** PUT /api/v1/email/workspaces/{connection}/people/{address}/link  { user_id: ?string } */
    public function link(Request $request, GoogleWorkspaceConnection $connection, string $address): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $address = $this->addressOn($connection, $address);

        $input = $request->validate(['user_id' => ['present', 'nullable', 'string', 'max:64']]);
        $user  = $input['user_id'] === null ? null : User::query()->where('is_active', true)->find($input['user_id']);

        abort_if($input['user_id'] !== null && $user === null, 422, 'That person is not an active member of this organization.');

        $this->signatures->link($connection, $address, $user);
        $this->audit->log('workspace.person_linked', $connection, metadata: ['address' => $address, 'person' => $user?->name]);

        return response()->json(['data' => ['address' => $address, 'user' => $user ? ['id' => $user->id, 'name' => $user->name] : null]]);
    }

    /** PUT /api/v1/email/workspaces/{connection}/signature-template  { html: ?string } */
    public function setTemplate(Request $request, GoogleWorkspaceConnection $connection): JsonResponse
    {
        $this->assertMayManage($request, $connection);

        $input = $request->validate(['html' => ['present', 'nullable', 'string', 'max:'.WorkspaceSignatures::MAX_LENGTH]]);

        $connection->forceFill(['signature_template' => $input['html']])->save();
        $this->audit->log('workspace.signature_template_changed', $connection);

        return response()->json(['data' => ['template' => $connection->signature_template]]);
    }

    /** PUT /api/v1/email/workspaces/{connection}/people/{address}/signature  { html: ?string } -- this person's own */
    public function setOwn(Request $request, GoogleWorkspaceConnection $connection, string $address): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $address = $this->addressOn($connection, $address);

        $input = $request->validate(['html' => ['present', 'nullable', 'string', 'max:'.WorkspaceSignatures::MAX_LENGTH]]);

        $this->signatures->setOwnSignature($connection, $address, $input['html']);

        return response()->json(['data' => ['address' => $address, 'has_own_signature' => $input['html'] !== null]]);
    }

    /** GET /api/v1/email/workspaces/{connection}/people/{address}/signature -- what Gmail has now, and what EzBizi would set */
    public function showSignature(Request $request, GoogleWorkspaceConnection $connection, string $address): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $address = $this->addressOn($connection, $address);

        try {
            $current = $this->signatures->current($address);
        } catch (GoogleWorkspaceRefused) {
            $current = null;
        }

        return response()->json(['data' => [
            'address' => $address,
            'current' => $current,
            'own'     => $this->signatures->sourceFor($connection, $address) !== $connection->signature_template
                ? $this->signatures->sourceFor($connection, $address) : null,
            'preview' => $this->rendered($connection, $address, null),
        ]]);
    }

    /** POST /api/v1/email/workspaces/{connection}/signatures/preview  { address, html? } */
    public function preview(Request $request, GoogleWorkspaceConnection $connection): JsonResponse
    {
        $this->assertMayManage($request, $connection);

        $input   = $request->validate([
            'address' => ['required', 'string', 'max:254'],
            'html'    => ['nullable', 'string', 'max:'.WorkspaceSignatures::MAX_LENGTH],
        ]);
        $address = $this->addressOn($connection, $input['address']);

        return response()->json(['data' => ['address' => $address, 'html' => $this->rendered($connection, $address, $input['html'] ?? null)]]);
    }

    /**
     * POST /api/v1/email/workspaces/{connection}/signatures/apply  { addresses: [..], html? }
     *
     * Each address gets its own signature if it has one, else the template --
     * or `html` for all of them when sent. Filled per person. One failure does
     * not stop the rest; every address is reported.
     */
    public function apply(Request $request, GoogleWorkspaceConnection $connection): JsonResponse
    {
        $this->assertMayManage($request, $connection);

        $input = $request->validate([
            'addresses'   => ['required', 'array', 'min:1', 'max:'.self::MAX_APPLY],
            'addresses.*' => ['string', 'max:254'],
            'html'        => ['nullable', 'string', 'max:'.WorkspaceSignatures::MAX_LENGTH],
        ]);

        if (($why = $this->signatures->notReadyBecause($connection)) !== null) {
            return response()->json(['message' => $why], 422);
        }

        $people  = collect($this->signatures->people($connection))->keyBy('address');
        $results = [];

        foreach (array_unique(array_map('mb_strtolower', $input['addresses'])) as $address) {
            if (! $people->has($address)) {
                $results[] = ['address' => $address, 'ok' => false, 'message' => 'Not a user on this Workspace.'];

                continue;
            }

            $html = $this->rendered($connection, $address, $input['html'] ?? null, $people->get($address));

            if ($html === null) {
                $results[] = ['address' => $address, 'ok' => false, 'message' => 'No signature to set: write the template first.'];

                continue;
            }

            try {
                $this->signatures->apply($connection, $address, $html);
                $results[] = ['address' => $address, 'ok' => true, 'message' => null];
            } catch (GoogleWorkspaceRefused) {
                $results[] = ['address' => $address, 'ok' => false, 'message' => 'Google refused the change for this address.'];
            }
        }

        $done = count(array_filter($results, fn ($r) => $r['ok']));
        $this->audit->log('workspace.signatures_applied', $connection, metadata: ['set' => $done, 'asked' => count($results)]);

        return response()->json(['data' => ['set' => $done, 'results' => $results]]);
    }

    /** @return array<string, mixed> */
    private function card(GoogleWorkspaceConnection $connection): array
    {
        $why = $this->signatures->notReadyBecause($connection);

        return [
            'id'                => $connection->getKey(),
            'domain'            => $connection->domain,
            'scope_label'       => $this->workspace->scopeLabel($connection),
            'status'            => $connection->status,
            'signatures_ready'  => $why === null,
            'signatures_reason' => $why,
            'has_template'      => $connection->signature_template !== null,
        ];
    }

    /** @param array<string, mixed>|null $person */
    private function rendered(GoogleWorkspaceConnection $connection, string $address, ?string $html, ?array $person = null): ?string
    {
        $source = $html ?? $this->signatures->sourceFor($connection, $address);

        if ($source === null) {
            return null;
        }

        $person ??= collect($this->signatures->people($connection))->firstWhere('address', $address) ?? ['address' => $address];

        return $this->signatures->render(
            $source,
            $person,
            $this->signatures->userFor($connection, $address),
            $this->workspace->scopeLabel($connection),
        );
    }

    private function addressOn(GoogleWorkspaceConnection $connection, string $address): string
    {
        $address = mb_strtolower(trim($address));

        abort_unless($this->workspace->onWorkspace($address, $connection->domain), 404);

        return $address;
    }

    private function assertMayManage(Request $request, ?GoogleWorkspaceConnection $connection = null): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('email.edit'),
            Response::HTTP_FORBIDDEN,
            Refusal::permission('manage Google Workspace')->message(),
        );

        abort_if(
            $connection !== null && ! $this->workspace->connectionsVisibleTo($user)->contains('id', $connection->getKey()),
            Response::HTTP_NOT_FOUND,
        );
    }
}
