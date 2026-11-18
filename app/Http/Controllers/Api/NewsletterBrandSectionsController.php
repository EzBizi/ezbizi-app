<?php

namespace App\Http\Controllers\Api;

use App\Models\NewsletterBrand;
use App\Models\NewsletterSection;
use App\Models\NewsletterSectionVersion;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Newsletter\BlockRenderer;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Newsletter\NewsletterBrandSettings;
use App\Support\Newsletter\NewsletterSections;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Newsletter P1b (2026-11-18): the company brand and saved sections.
 *
 * Both are filed against the view they were made in (organization, company
 * or franchise), like newsletters, and read through the same visibility rule:
 * a sibling company never sees another's brand or sections, and a row out of
 * view is a 404.
 */
class NewsletterBrandSectionsController extends Controller
{
    public function __construct(private readonly BlockRenderer $renderer) {}

    /** GET /newsletter/brand: this view's brand, or empty settings. */
    public function brand(Request $request): JsonResponse
    {
        $user = $this->mayView($request);
        [$type, $id] = $this->filingScope($user);
        $row = NewsletterBrand::query()->where('scope_type', $type)->where('scope_id', $id)->first();

        return response()->json(['data' => $this->presentBrand($row, $type, $id, $user)]);
    }

    /** PUT /newsletter/brand: replace this view's brand (newsletter.edit). */
    public function saveBrand(Request $request): JsonResponse
    {
        $user = $this->mayEdit($request);
        [$type, $id] = $this->filingScope($user);
        abort_unless(OwnedScope::mayWrite($user, $user->organization_id, $type, $id), Status::HTTP_FORBIDDEN,
            'Switch to a view that can manage this brand.');
        $request->validate(['settings' => ['present', 'nullable', 'array']]);

        $row = NewsletterBrand::query()->firstOrNew(['scope_type' => $type, 'scope_id' => $id]);
        $row->settings = NewsletterBrandSettings::clean($request->input('settings')) ?? [];
        $row->updated_by = $user->getKey();
        $row->save();

        return response()->json(['data' => $this->presentBrand($row, $type, $id, $user)]);
    }

    /** GET /newsletter/sections: saved sections in view, plus the starters. */
    public function sections(Request $request): JsonResponse
    {
        $user = $this->mayView($request);
        $rows = NewsletterAccess::visible(NewsletterSection::query(), $user)->orderByDesc('updated_at')->get()
            ->filter(fn (NewsletterSection $s) => $this->inView($user, $s))->values();

        return response()->json([
            'data' => $rows->map(fn (NewsletterSection $s) => $this->presentSection($s, $user))->all(),
            'starters' => NewsletterSections::starters(),
        ]);
    }

    /** GET /newsletter/sections/{id}: the latest version's blocks. */
    public function section(Request $request, string $section): JsonResponse
    {
        $user = $this->mayView($request);

        return response()->json(['data' => $this->presentSection($this->find($user, $section), $user)]);
    }

    /** POST /newsletter/sections: save blocks as a new section, version 1. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->mayEdit($request);
        $data = $this->validated($request);
        [$type, $id] = $this->filingScope($user);
        abort_unless(OwnedScope::mayWrite($user, $user->organization_id, $type, $id), Status::HTTP_FORBIDDEN,
            'Switch to a view that can save sections.');

        $section = DB::transaction(function () use ($user, $data, $type, $id) {
            $section = NewsletterSection::create([
                'scope_type' => $type, 'scope_id' => $id, 'name' => $data['name'], 'blocks' => $data['blocks'],
                'mode' => $data['mode'], 'version' => 1, 'thumbnail_url' => $this->firstImage($data['blocks']),
                'updated_by' => $user->getKey(),
            ]);
            NewsletterSectionVersion::create([
                'newsletter_section_id' => $section->getKey(), 'version' => 1, 'blocks' => $data['blocks'],
                'updated_by' => $user->getKey(),
            ]);

            return $section;
        });

        return response()->json(['data' => $this->presentSection($section->fresh(), $user)], Status::HTTP_CREATED);
    }

    /**
     * PUT /newsletter/sections/{id}: publish changes. New blocks are a NEW
     * version; newsletters linked to the old one keep it until each is
     * updated on purpose. A rename alone is not a new version.
     */
    public function update(Request $request, string $section): JsonResponse
    {
        $user = $this->mayEdit($request);
        $data = $this->validated($request, partial: true);

        return DB::transaction(function () use ($user, $data, $section) {
            $row = $this->find($user, $section, lock: true);
            abort_unless(NewsletterAccess::mayWrite($user, $row), Status::HTTP_FORBIDDEN,
                'Switch to a view that can manage this section.');
            if (isset($data['name'])) {
                $row->name = $data['name'];
            }
            if (isset($data['mode'])) {
                $row->mode = $data['mode'];
            }
            if (isset($data['blocks']) && json_encode($data['blocks']) !== json_encode($row->blocks)) {
                $row->version = (int) $row->version + 1;
                $row->blocks = $data['blocks'];
                $row->thumbnail_url = $this->firstImage($data['blocks']);
                NewsletterSectionVersion::create([
                    'newsletter_section_id' => $row->getKey(), 'version' => $row->version, 'blocks' => $data['blocks'],
                    'updated_by' => $user->getKey(),
                ]);
            }
            $row->updated_by = $user->getKey();
            $row->save();

            return response()->json(['data' => $this->presentSection($row->fresh(), $user)]);
        });
    }

    /** DELETE /newsletter/sections/{id}: off the list; linked newsletters keep their versions. */
    public function destroy(Request $request, string $section): JsonResponse
    {
        $user = $this->mayEdit($request);
        $row = $this->find($user, $section);
        abort_unless(NewsletterAccess::mayWrite($user, $row), Status::HTTP_FORBIDDEN,
            'Switch to a view that can manage this section.');
        $row->delete();

        return response()->json(['data' => ['id' => $section]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'blocks' => [$req, 'array', 'min:1', 'max:40'],
            'mode' => ['sometimes', 'in:copy,linked'],
        ]);
        $out = [];
        if ($request->has('name')) {
            $out['name'] = trim((string) $request->input('name'));
        }
        if ($request->has('blocks')) {
            $blocks = NewsletterSections::cleanBlocks((array) $request->input('blocks'));
            if ($blocks === []) {
                throw ValidationException::withMessages(['blocks' => 'A section needs at least one block it can hold.']);
            }
            $out['blocks'] = $blocks;
        }
        $out['mode'] = $request->input('mode', $partial ? null : NewsletterSection::MODE_COPY);

        return array_filter($out, fn ($v) => $v !== null);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function firstImage(array $blocks): ?string
    {
        foreach ($blocks as $b) {
            if (($b['type'] ?? null) === 'image' && str_starts_with(strtolower((string) ($b['src'] ?? '')), 'https://')) {
                return mb_substr((string) $b['src'], 0, 2048);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function presentSection(NewsletterSection $s, User $user): array
    {
        return [
            'id' => $s->getKey(),
            'name' => $s->name,
            'mode' => $s->mode,
            'version' => (int) $s->version,
            'blocks' => $s->blocks ?? [],
            'thumbnail_url' => $s->thumbnail_url,
            'scope_type' => $s->scope_type,
            'may_edit' => NewsletterAccess::mayWrite($user, $s),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentBrand(?NewsletterBrand $row, string $type, string $id, User $user): array
    {
        return [
            'settings' => (object) ($row?->settings ?? []),
            'fonts' => array_keys(NewsletterBrandSettings::FONTS),
            'scope_type' => $type,
            'may_edit' => $user->hasPermission('newsletter.edit')
                && OwnedScope::mayWrite($user, $user->organization_id, $type, $id),
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ];
    }

    private function find(User $user, string $id, bool $lock = false): NewsletterSection
    {
        $query = NewsletterAccess::visible(NewsletterSection::query(), $user);
        $row = ($lock ? $query->lockForUpdate() : $query)->find($id);
        abort_if($row === null || ! $this->inView($user, $row), Status::HTTP_NOT_FOUND);

        return $row;
    }

    private function inView(User $user, NewsletterSection $s): bool
    {
        $stand = new SocialConnection;
        $stand->forceFill(['scope_type' => $s->scope_type, 'scope_id' => $s->scope_id]);

        return app(SocialConnectionAccess::class)->inView($user, $stand);
    }

    /** @return array{0: string, 1: string} */
    private function filingScope(User $user): array
    {
        $view = ViewScope::forUser($user);

        return $view->isOrganization()
            ? [SocialConnection::SCOPE_ORGANIZATION, (string) $user->organization_id]
            : [$view->type, (string) $view->id];
    }

    private function mayView(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hasPermission('newsletter.view'), Status::HTTP_FORBIDDEN,
            Refusal::permission('open Newsletter')->message());

        return $user;
    }

    private function mayEdit(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hasPermission('newsletter.edit'), Status::HTTP_FORBIDDEN,
            Refusal::permission('edit a newsletter')->message());

        return $user;
    }
}
