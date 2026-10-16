<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Models\SocialLibraryItem;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Files\UploadLimits;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Social Media's standard media library (A1 poster takeover, 2026-10-16): the
 * pictures and videos a company posts again and again, uploaded once and
 * picked in Compose (POST social/posts with `library_item`).
 *
 * Reading is the Compose tab; adding and removing are marketing.edit. Scoped
 * like newsletters - filed where the view stands, visible downward.
 */
class SocialLibraryController extends Controller
{
    public function __construct(
        private readonly FileStore $files,
        private readonly UploadLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = NewsletterAccess::visible(SocialLibraryItem::query(), $request->user())->latest()->get();

        return response()->json(['data' => $items->map(fn (SocialLibraryItem $i) => $this->present($i))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->mayEdit($request, 'add to the media library');
        $this->limits->assertUploadArrived($request, 'file');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimes:'.implode(',', SocialMediaKind::extensions()), 'max:'.$this->limits->maxKilobytes()],
        ], [
            'file.mimes' => 'The library holds pictures and video: '.implode(', ', SocialMediaKind::extensions()).'.',
            'file.max' => $this->limits->tooLargeMessage(),
        ]);

        $view = ViewScope::forUser($user);
        [$scopeType, $scopeId] = $view->isOrganization()
            ? [SocialConnection::SCOPE_ORGANIZATION, (string) $user->organization_id]
            : [$view->type, (string) $view->id];

        abort_unless(OwnedScope::mayWrite($user, $user->organization_id, $scopeType, $scopeId),
            Status::HTTP_FORBIDDEN, 'Choose a view where your assignments allow adding to the library.');

        $file = $request->file('file');
        $disk = $this->files->defaultDisk();

        $item = SocialLibraryItem::create([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'name' => trim((string) ($data['name'] ?? '')) ?: $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $this->files->put($file, $this->files->directoryFor('social-library', $user->organization_id), $disk),
            'size' => (int) $file->getSize(),
            'created_by' => $user->getKey(),
        ]);

        $this->audit->log('social.library_added', $item, ['name' => $item->name], actor: $user);

        return response()->json(['data' => $this->present($item)], Status::HTTP_CREATED);
    }

    public function update(Request $request, string $item): JsonResponse
    {
        $user = $this->mayEdit($request, 'rename a library item');
        $row = $this->find($user, $item);

        abort_unless(NewsletterAccess::mayWrite($user, $row), Status::HTTP_FORBIDDEN,
            'Switch to a view that can manage this library item.');

        $row->name = $request->validate(['name' => ['required', 'string', 'max:120']])['name'];
        $this->audit->logChange('social.library_renamed', $row, $user);
        $row->save();

        return response()->json(['data' => $this->present($row)]);
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        $user = $this->mayEdit($request, 'remove from the media library');
        $row = $this->find($user, $item);

        abort_unless(NewsletterAccess::mayWrite($user, $row), Status::HTTP_FORBIDDEN,
            'Switch to a view that can manage this library item.');

        // Posts made from it keep their own copy (SocialPostsController), so
        // removing the file here takes nothing already scheduled with it.
        $this->audit->log('social.library_removed', $row, ['name' => $row->name], actor: $user);
        $this->files->delete($row->path, $row->disk);
        $row->delete();

        return response()->json(['data' => ['id' => $item]]);
    }

    /** The file itself, for the library's thumbnails. */
    public function file(Request $request, string $item): StreamedResponse
    {
        $row = $this->find($request->user(), $item);

        return $this->files->serve($row->path, $row->disk, $row->mime(), $row->name, 'inline');
    }

    public function find(User $user, string $id): SocialLibraryItem
    {
        return NewsletterAccess::visible(SocialLibraryItem::query(), $user)->find($id)
            ?? abort(Status::HTTP_NOT_FOUND);
    }

    private function mayEdit(Request $request, string $doing): User
    {
        $user = $request->user();
        abort_unless($user->hasPermission('marketing.edit'), Status::HTTP_FORBIDDEN, Refusal::permission($doing)->message());

        return $user;
    }

    /** @return array<string, mixed> */
    private function present(SocialLibraryItem $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'kind' => $i->kind(),
            'size' => $i->size,
            'scope_type' => $i->scope_type,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
