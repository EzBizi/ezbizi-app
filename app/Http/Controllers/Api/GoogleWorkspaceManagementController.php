<?php

namespace App\Http\Controllers\Api;

use App\Models\GoogleWorkspaceConnection;
use App\Models\GoogleWorkspaceSignatureTemplate;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Email\GoogleWorkspace;
use App\Support\Email\GoogleWorkspaceRefused;
use App\Support\Email\WorkspaceSignatures;
use App\Support\Access\Refusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /** Signature pictures, on the local disk and served by photo(). */
    private const PHOTO_DIR = 'signature-photos';

    public function __construct(
        private readonly GoogleWorkspace $workspace,
        private readonly WorkspaceSignatures $signatures,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/email/workspaces */
    public function index(Request $request): JsonResponse
    {
        $this->assertMayManage($request, null, write: false);

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
        $this->assertMayManage($request, $connection, write: false);

        try {
            $people = $this->signatures->people($connection);
        } catch (GoogleWorkspaceRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            ...$this->card($connection),
            'template'     => $connection->signature_template,
            'placeholders' => WorkspaceSignatures::PLACEHOLDERS,
            'person_fields' => WorkspaceSignatures::PERSON_FIELDS,
            'templates'    => $this->signatures->templates($connection)->map(fn (GoogleWorkspaceSignatureTemplate $t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'design'     => $t->design,
                'html'       => $t->html,
                'is_default' => $t->id === $connection->default_signature_template_id,
            ])->values(),
            'max_length'   => WorkspaceSignatures::MAX_LENGTH,
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
        $this->assertMayManage($request, $connection, write: false);
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
        $this->assertMayManage($request, $connection, write: false);

        $input   = $request->validate([
            'address'     => ['required', 'string', 'max:254'],
            'html'        => ['nullable', 'string', 'max:'.WorkspaceSignatures::MAX_LENGTH],
            'template_id' => ['nullable', 'string'],
        ]);
        $address = $this->addressOn($connection, $input['address']);
        $html    = $input['html'] ?? (isset($input['template_id']) ? $this->template($connection, $input['template_id'])->html : null);

        return response()->json(['data' => ['address' => $address, 'html' => $this->rendered($connection, $address, $html)]]);
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

    /** POST /api/v1/email/workspaces/{connection}/signature-templates  { name, html, design? } */
    public function storeTemplate(Request $request, GoogleWorkspaceConnection $connection): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $input = $this->validateTemplate($request, creating: true);

        $template = GoogleWorkspaceSignatureTemplate::query()->create([
            'google_workspace_connection_id' => $connection->getKey(),
            ...$input,
        ]);
        $this->audit->log('workspace.signature_template_created', $connection, metadata: ['template' => $template->id]);

        return response()->json(['data' => ['id' => $template->id]], 201);
    }

    /** PUT /api/v1/email/workspaces/{connection}/signature-templates/{template}  { name?, html?, design? } */
    public function updateTemplate(Request $request, GoogleWorkspaceConnection $connection, string $template): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $row = $this->template($connection, $template);
        $row->fill($this->validateTemplate($request, creating: false))->save();
        $this->audit->log('workspace.signature_template_changed', $connection, metadata: ['template' => $row->id]);

        return response()->json(['data' => ['id' => $row->id]]);
    }

    /** DELETE /api/v1/email/workspaces/{connection}/signature-templates/{template} -- its people go back to the default. */
    public function destroyTemplate(Request $request, GoogleWorkspaceConnection $connection, string $template): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $row = $this->template($connection, $template);

        \App\Models\GoogleWorkspacePerson::query()
            ->where('google_workspace_connection_id', $connection->getKey())
            ->where('signature_template_id', $row->id)
            ->update(['signature_template_id' => null]);

        if ($connection->default_signature_template_id === $row->id) {
            $connection->forceFill(['default_signature_template_id' => null])->save();
        }

        $row->delete();
        $this->audit->log('workspace.signature_template_deleted', $connection, metadata: ['template' => $template]);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** PUT /api/v1/email/workspaces/{connection}/signature-default  { template_id: ?string } -- everyone not given another. */
    public function setDefault(Request $request, GoogleWorkspaceConnection $connection): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $input = $request->validate(['template_id' => ['present', 'nullable', 'string']]);
        $id    = $input['template_id'] === null ? null : $this->template($connection, $input['template_id'])->id;

        $connection->forceFill(['default_signature_template_id' => $id])->save();
        $this->audit->log('workspace.signature_default_changed', $connection, metadata: ['template' => $id]);

        return response()->json(['data' => ['template_id' => $id]]);
    }

    /** PUT /api/v1/email/workspaces/{connection}/signatures/assign  { addresses: [..], template_id: ?string } */
    public function assign(Request $request, GoogleWorkspaceConnection $connection): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $input = $request->validate([
            'addresses'   => ['required', 'array', 'min:1', 'max:500'],
            'addresses.*' => ['string', 'max:254'],
            'template_id' => ['present', 'nullable', 'string'],
        ]);
        $id        = $input['template_id'] === null ? null : $this->template($connection, $input['template_id'])->id;
        $addresses = array_values(array_unique(array_map(fn ($a) => $this->addressOn($connection, $a), $input['addresses'])));

        $this->signatures->assign($connection, $addresses, $id);
        $this->audit->log('workspace.signature_assigned', $connection, metadata: ['template' => $id, 'count' => count($addresses)]);

        return response()->json(['data' => ['assigned' => count($addresses)]]);
    }

    /** PUT /api/v1/email/workspaces/{connection}/people/{address}/fields  { fields: { mobile: "...", ... } } */
    public function setFields(Request $request, GoogleWorkspaceConnection $connection, string $address): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $address = $this->addressOn($connection, $address);
        $input   = $request->validate(['fields' => ['required', 'array'], 'fields.*' => ['nullable', 'string', 'max:500']]);

        $this->signatures->setFields($connection, $address, $input['fields']);

        return response()->json(['data' => ['address' => $address]]);
    }

    /**
     * POST /api/v1/email/workspaces/{connection}/people/{address}/photo  (multipart `photo`)
     *
     * Gmail shows a signature picture by fetching it, so it has to sit at a
     * public address. Kept apart from the profile photo, which is private to
     * the organization; this one is meant to be seen by everyone mailed.
     */
    public function uploadPhoto(Request $request, GoogleWorkspaceConnection $connection, string $address): JsonResponse
    {
        $this->assertMayManage($request, $connection);
        $address = $this->addressOn($connection, $address);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:2048']]);

        $file = $request->file('photo');
        $name = Str::random(40).'.'.$file->extension();
        Storage::disk('local')->putFileAs(self::PHOTO_DIR, $file, $name);
        $url = url('/api/v1/signature-photos/'.$name);

        $this->signatures->setFields($connection, $address, ['photo_url' => $url]);

        return response()->json(['data' => ['photo_url' => $url]]);
    }

    /** GET /api/v1/signature-photos/{name} -- public on purpose, see uploadPhoto(). */
    public function photo(string $name): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}\.(jpg|jpeg|png|gif|webp)$/', $name) === 1, 404);
        abort_unless(Storage::disk('local')->exists(self::PHOTO_DIR.'/'.$name), 404);

        return Storage::disk('local')->response(self::PHOTO_DIR.'/'.$name, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name'   => [$need, 'string', 'max:80'],
            'html'   => [$need, 'string', 'max:'.WorkspaceSignatures::MAX_LENGTH],
            'design' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    private function template(GoogleWorkspaceConnection $connection, string $id): GoogleWorkspaceSignatureTemplate
    {
        return GoogleWorkspaceSignatureTemplate::query()
            ->where('google_workspace_connection_id', $connection->getKey())
            ->findOrFail($id);
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
            // Shown as "Update permissions" while something is missing: Google
            // Admin, pre-filled, so the company's admin only clicks Authorize.
            'authorize_url'     => $this->workspace->delegationUrl([WorkspaceSignatures::SCOPE_SETTINGS]),
            'has_template'      => $connection->signature_template !== null || $this->signatures->templates($connection)->isNotEmpty(),
            'may_edit'          => request()->user()?->hasPermission('email.edit') ?? false,
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
            $this->signatures->companyLogoUrl($connection),
        );
    }

    private function addressOn(GoogleWorkspaceConnection $connection, string $address): string
    {
        $address = mb_strtolower(trim($address));

        abort_unless($this->workspace->onWorkspace($address, $connection->domain), 404);

        return $address;
    }

    /**
     * The tab (email.workspace.view, on the route) is enough to READ; changing
     * anything also takes `email.edit`, as managing Workspace does in Settings.
     */
    private function assertMayManage(Request $request, ?GoogleWorkspaceConnection $connection = null, bool $write = true): void
    {
        $user = $request->user();

        abort_unless(
            ! $write || $user->hasPermission('email.edit'),
            Response::HTTP_FORBIDDEN,
            Refusal::permission('manage Google Workspace')->message(),
        );

        abort_if(
            $connection !== null && ! $this->workspace->connectionsVisibleTo($user)->contains('id', $connection->getKey()),
            Response::HTTP_NOT_FOUND,
        );
    }
}
