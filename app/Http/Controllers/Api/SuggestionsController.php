<?php

namespace App\Http\Controllers\Api;

use App\Models\Suggestion;
use App\Support\Files\FileStore;
use App\Support\Files\UploadLimits;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The author's half of Suggestions.
 *
 * ── Why every query filters user_id as well as the tenant ────────
 *
 * BelongsToTenant already scopes reads to the organization, and for every other
 * module that is the whole boundary. Here it is only half of one: the point of
 * this table is that a colleague — inside the same tenant, possibly holding
 * every permission the organization can grant — must not be able to read it.
 * So the filter is explicit on every path, including the ones that look like
 * they cannot need it.
 *
 * ── Why there is no permission check ─────────────────────────────
 *
 * There is no permission to check. Suggestions is deliberately absent from
 * ModuleRegistry so that no organization can take it away, which means
 * PermissionCatalogue publishes no key for it and Role::hasPermission has
 * nothing to answer. Anybody signed in reaches this. See Suggestion's docblock.
 */
class SuggestionsController extends Controller
{
    public function __construct(
        private readonly FileStore $store,
        private readonly UploadLimits $limits,
    ) {}

    /** GET /api/v1/suggestions — your own, newest first. */
    public function index(Request $request): JsonResponse
    {
        $rows = Suggestion::query()
            ->where('user_id', $request->user()->getKey())
            ->latest('created_at')
            ->get()
            ->map(fn (Suggestion $row) => $this->row($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/suggestions */
    public function store(Request $request): JsonResponse
    {
        foreach (array_keys($request->file('files') ?? []) as $i) {
            $this->limits->assertUploadArrived($request, "files.$i");
        }

        $input = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'module' => $this->moduleRule(),
            'files' => ['nullable', 'array', 'max:'.Suggestion::MAX_FILES],
            'files.*' => [
                'file',
                'extensions:'.implode(',', Suggestion::FILE_EXTENSIONS),
                // `extensions` checks the name, `mimes` the detected CONTENT, so
                // a page renamed to .md is refused as html.
                'mimes:'.implode(',', Suggestion::FILE_EXTENSIONS),
                'max:'.$this->limits->maxKilobytes(),
            ],
        ], [
            'files.max' => 'Attach at most '.Suggestion::MAX_FILES.' files.',
            'files.*.max' => $this->limits->tooLargeMessage(),
            'files.*.extensions' => 'Attach a picture, a PDF, or a .md or .txt file.',
            'files.*.mimes' => 'Attach a picture, a PDF, or a .md or .txt file.',
        ]);

        $body = trim($input['body']);

        if ($body === '') {
            return response()->json([
                'message' => 'Write the suggestion first.',
                'errors' => ['body' => ['Write the suggestion first.']],
            ], 422);
        }

        $files = array_map(fn (UploadedFile $file) => $this->keep($request, $file), $input['files'] ?? []);

        try {
            $row = Suggestion::create([
                'user_id' => $request->user()->getKey(),
                'body' => $body,
                'module' => $input['module'] ?? null,
                'files' => $files ?: null,
                'status' => Suggestion::STATUS_NEW,
            ]);
        } catch (Throwable $e) {
            $this->forget($files);

            throw $e;
        }

        return response()->json(['data' => $this->row($row)], 201);
    }

    /** PATCH /api/v1/suggestions/{suggestion} */
    public function update(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'module' => ['sometimes', ...$this->moduleRule()],
        ]);

        $row = $this->mine($request, $id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        if ($row->status !== Suggestion::STATUS_NEW) {
            return $this->frozen($row);
        }

        $body = trim($input['body']);

        if ($body === '') {
            return response()->json([
                'message' => 'Write the suggestion first.',
                'errors' => ['body' => ['Write the suggestion first.']],
            ], 422);
        }

        $row->body = $body;
        if (array_key_exists('module', $input)) {
            $row->module = $input['module'];
        }
        $row->save();

        return response()->json(['data' => $this->row($row)]);
    }

    /** DELETE /api/v1/suggestions/{suggestion} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = $this->mine($request, $id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        if ($row->status !== Suggestion::STATUS_NEW) {
            return $this->frozen($row);
        }

        $files = $row->files ?? [];
        $row->delete();
        $this->forget($files);

        return response()->json([], 204);
    }

    /** GET /api/v1/suggestions/{suggestion}/files/{index} — one of your own attachments. */
    public function file(Request $request, string $id, int $index): StreamedResponse
    {
        $row = $this->mine($request, $id);

        abort_if($row === null, 404);

        return self::serve($this->store, $row, $index);
    }

    /**
     * The bytes of one attachment. Shared with the staff queue, which reaches
     * the row by its own unscoped query.
     */
    public static function serve(FileStore $store, Suggestion $row, int $index): StreamedResponse
    {
        $file = ($row->files ?? [])[$index] ?? null;

        abort_if($file === null, 404);

        return $store->serve($file['path'], $file['disk'], $file['mime'], $file['name'], disposition: 'attachment');
    }

    /** A module key the registry knows, `settings`, or null for "something new". */
    private function moduleRule(): array
    {
        return ['nullable', 'string', Rule::in([
            ...array_keys(app(ModuleRegistry::class)->all()),
            Suggestion::MODULE_SETTINGS,
        ])];
    }

    /**
     * Stored straight through FileStore, not AttachmentUploader: that one
     * writes an organization audit row, which is exactly what this module
     * keeps from the organization. See Suggestion's docblock.
     *
     * @return array{name: string, mime: string, size: int, disk: string, path: string}
     */
    private function keep(Request $request, UploadedFile $file): array
    {
        $disk = $this->store->defaultDisk();

        return [
            'name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'disk' => $disk,
            'path' => $this->store->put(
                $file,
                $this->store->directoryFor('suggestions', $request->user()->organization_id),
                $disk,
            ),
        ];
    }

    private function forget(array $files): void
    {
        foreach ($files as $file) {
            $this->store->delete($file['path'] ?? null, $file['disk'] ?? null);
        }
    }

    /**
     * Yours, or nothing.
     *
     * 404 rather than 403 for a colleague's row, deliberately: 403 would confirm
     * that a suggestion with that id exists, which is a fact this module keeps
     * from them.
     */
    private function mine(Request $request, string $id): ?Suggestion
    {
        return Suggestion::query()
            ->where('user_id', $request->user()->getKey())
            ->find($id);
    }

    /**
     * Why it can no longer be touched, in words that name the actual state.
     *
     * Two different sentences because they are two different situations to be
     * in: one is "somebody is reading it right now", the other is "this has been
     * answered and the answer is on screen above".
     */
    private function frozen(Suggestion $row): JsonResponse
    {
        $message = $row->status === Suggestion::STATUS_REVIEWING
            ? 'Somebody at EzBizi is reading this one, so it is fixed while they do. '
                .'Send another if there is more to say.'
            : 'This one has been answered, so it stays as it was written. '
                .'Send another if there is more to say.';

        return response()->json([
            'message' => $message,
            'errors' => ['body' => [$message]],
        ], 422);
    }

    /** @return array<string, mixed> */
    private function row(Suggestion $row): array
    {
        return [
            'id' => $row->id,
            'body' => $row->body,
            'module' => $row->module,
            'module_label' => $row->moduleLabel(),
            'files' => $row->presentFiles(),
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'progress' => $row->progress,
            'release_version' => $row->release_version,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
