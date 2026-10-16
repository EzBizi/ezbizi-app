<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformHelpVideo;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Staff › Help videos: which tab has which how-to video (UI queue 81).
 *
 * Staff-only for the reason the client versions are: one video is shown to
 * every organization, so no tenant may set it. A new or replaced video is a
 * save here, never a release.
 */
class StaffHelpVideosController extends Controller
{
    /**
     * The shell's own pages, which are not modules but carry the same header,
     * walkthrough and video (UI queue 99).
     */
    private const PAGES = [
        'settings' => 'Settings',
        'suggestions' => 'Suggestions',
        'development' => 'Development',
        'account' => 'Your account',
    ];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/staff/help-videos — every module, with its video or null, so
     * the tabs that have none are the visible half of the list.
     */
    public function index(): JsonResponse
    {
        $rows = PlatformHelpVideo::all()->keyBy('module_key');

        $labels = collect($this->modules->all())
            ->mapWithKeys(fn ($module) => [$module->key => $module->label])
            ->merge(self::PAGES);

        $data = $labels
            ->map(fn (string $label, string $key) => [
                'module_key' => $key,
                'label' => $label,
                'video' => $rows->get($key)?->present(),
                'changed_by' => $rows->get($key)?->changed_by,
                'changed_at' => $rows->get($key)?->updated_at?->toIso8601String(),
            ])
            ->sortBy('label')
            ->values();

        return response()->json(['data' => $data]);
    }

    /** PUT /api/v1/staff/help-videos/{module}: the whole video, replaced. */
    public function update(Request $request, string $module): JsonResponse
    {
        $this->known($module);

        $input = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'title' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:36000'],
            'chapters' => ['nullable', 'array', 'max:50'],
            // Seconds; a fraction (17.2) is kept as the second it falls in.
            'chapters.*.at' => ['required', 'numeric', 'min:0', 'max:36000'],
            'chapters.*.label' => ['required', 'string', 'max:120'],
            'captions_url' => ['nullable', 'string', 'max:2048', 'url:https'],
        ]);

        $staff = $request->user();
        $label = "{$staff->name} <{$staff->email}>";
        $before = PlatformHelpVideo::query()->where('module_key', $module)->first()?->present();

        $row = PlatformHelpVideo::updateOrCreate(['module_key' => $module], [
            'url' => $input['url'],
            'title' => $input['title'] ?? null,
            'duration_seconds' => $input['duration_seconds'] ?? null,
            'chapters' => collect($input['chapters'] ?? [])
                ->map(fn ($c) => ['at' => (int) floor((float) $c['at']), 'label' => $c['label']])
                ->sortBy('at')->values()->all(),
            'captions_url' => $input['captions_url'] ?? null,
            'changed_by' => $label,
        ]);

        $this->audit->logStaff(
            action: 'staff.help_video_changed',
            staffId: $staff->getKey(),
            staffLabel: $label,
            target: $row,
            metadata: ['module' => $module, 'before' => $before, 'after' => $row->present()],
        );

        return response()->json(['data' => $row->present()]);
    }

    /** DELETE /api/v1/staff/help-videos/{module}: the tab's ▶ goes away. */
    public function destroy(Request $request, string $module): Response
    {
        $this->known($module);
        $row = PlatformHelpVideo::query()->where('module_key', $module)->first();

        if ($row !== null) {
            $staff = $request->user();
            $this->audit->logStaff(
                action: 'staff.help_video_changed',
                staffId: $staff->getKey(),
                staffLabel: "{$staff->name} <{$staff->email}>",
                target: $row,
                metadata: ['module' => $module, 'before' => $row->present(), 'after' => null],
            );
            $row->delete();
        }

        return response()->noContent();
    }

    private function known(string $module): void
    {
        if ($this->modules->get($module) === null && ! isset(self::PAGES[$module])) {
            throw ValidationException::withMessages(['module' => "'{$module}' is not a module."]);
        }
    }
}
