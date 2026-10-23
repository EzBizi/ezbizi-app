<?php

namespace App\Http\Controllers\Api;

use App\Models\AssistantAnswer;
use App\Models\AssistantEntry;
use App\Models\AssistantManual;
use App\Models\AssistantPart;
use App\Support\Ai\GrokCli;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI Assistance › Parts, Manuals and Answers: what the Chimney Tech Assistant
 * reads, and every answer it gave (approved mockup tech-assistant.html).
 *
 * Organization-wide, like the WhatsApp line it answers on. Reading is each
 * tab's `view` (the route), writing `ai_assistance.manage` (the route).
 */
class TechAssistantController extends Controller
{
    /** 40 MB: a full installation manual. */
    private const MANUAL_KILOBYTES = 40960;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly FileStore $files,
    ) {}

    // ── Parts ─────────────────────────────────────────────────────

    /** GET /api/v1/ai-assistance/parts?q=&status= */
    public function parts(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(AssistantPart::STATUSES)],
        ]);

        $all = AssistantPart::query()->orderBy('brand')->orderBy('model')->orderBy('part_name')->get();
        $q = mb_strtolower(trim((string) ($input['q'] ?? '')));

        $rows = $all
            ->when($q !== '', fn ($c) => $c->filter(fn (AssistantPart $p) => str_contains(mb_strtolower(implode(' ', [
                $p->brand, $p->model, $p->part_name, $p->part_number, $p->superseded_by,
                implode(' ', (array) $p->model_aliases), implode(' ', (array) $p->part_aliases),
            ])), $q)))
            ->when(($input['status'] ?? null) !== null, fn ($c) => $c->where('status', $input['status']));

        return response()->json([
            'data' => $rows->values()->map(fn (AssistantPart $p) => $this->presentPart($p)),
            'summary' => [
                'all' => $all->count(),
                'models' => $all->map(fn ($p) => mb_strtolower($p->brand.'|'.$p->model))->unique()->count(),
                'confirmed' => $all->where('status', 'confirmed')->count(),
                'needs_review' => $all->where('status', 'needs_review')->count(),
                'discontinued' => $all->where('status', 'discontinued')->count(),
            ],
        ]);
    }

    /** POST /api/v1/ai-assistance/parts */
    public function storePart(Request $request): JsonResponse
    {
        $input = $request->validate($this->partRules());
        $input['created_by'] = $input['updated_by'] = $request->user()->getKey();

        $part = DB::transaction(function () use ($input) {
            $part = AssistantPart::create($input);
            $this->audit->log('assistant_part.created', $part);

            return $part;
        });

        return response()->json(['data' => $this->presentPart($part->fresh())], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/ai-assistance/parts/{part} */
    public function updatePart(Request $request, AssistantPart $part): JsonResponse
    {
        $input = $request->validate($this->partRules(updating: true));

        DB::transaction(function () use ($part, $input, $request) {
            $part->fill($input);
            if (! $part->isDirty()) {
                return;
            }
            $part->updated_by = $request->user()->getKey();
            $this->audit->logChange('assistant_part.updated', $part);
            $part->save();
        });

        return response()->json(['data' => $this->presentPart($part->fresh())]);
    }

    /** DELETE /api/v1/ai-assistance/parts/{part} */
    public function destroyPart(AssistantPart $part): Response
    {
        DB::transaction(function () use ($part) {
            $this->audit->log('assistant_part.deleted', $part, metadata: ['part_number' => $part->part_number]);
            $part->delete();
        });

        return response()->noContent();
    }

    // ── Manuals ───────────────────────────────────────────────────

    /** GET /api/v1/ai-assistance/manuals */
    public function manuals(): JsonResponse
    {
        return response()->json([
            'data' => AssistantManual::query()->orderBy('brand')->orderBy('title')->get()
                ->map(fn (AssistantManual $m) => $this->presentManual($m)),
        ]);
    }

    /** POST /api/v1/ai-assistance/manuals (multipart: file, brand, models, title, revision) */
    public function storeManual(Request $request): JsonResponse
    {
        $input = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:'.self::MANUAL_KILOBYTES],
            'brand' => ['required', 'string', 'max:80'],
            'models' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:160'],
            'revision' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $file = $request->file('file');
        $pages = $this->pagesOf($file->getRealPath());
        $path = $this->files->put($file, $this->files->directoryFor('assistant-manuals', $this->tenant->idOrFail()));

        $manual = DB::transaction(function () use ($input, $path, $pages, $request) {
            $manual = AssistantManual::create([
                'brand' => $input['brand'],
                'models' => $input['models'],
                'title' => $input['title'],
                'revision' => $input['revision'] ?? null,
                'path' => $path,
                'page_count' => count($pages),
                'pages' => $pages,
                'created_by' => $request->user()->getKey(),
            ]);
            $this->audit->log('assistant_manual.created', $manual);

            return $manual;
        });

        return response()->json(['data' => $this->presentManual($manual->fresh())], Response::HTTP_CREATED);
    }

    /** DELETE /api/v1/ai-assistance/manuals/{manual} */
    public function destroyManual(AssistantManual $manual): Response
    {
        DB::transaction(function () use ($manual) {
            $this->audit->log('assistant_manual.deleted', $manual, metadata: ['title' => $manual->title]);
            $manual->delete();
        });
        $this->files->delete($manual->path);

        return response()->noContent();
    }

    /**
     * The PDF's text, one string per page: `pdftotext` when the box has it
     * (pages split on form feeds), else Grok reading the file, else nothing
     * - the file is kept either way and can be uploaded again.
     *
     * @return list<string>
     */
    private function pagesOf(string $pdf): array
    {
        try {
            $result = Process::timeout(60)->run(['pdftotext', '-layout', $pdf, '-']);
            if ($result->successful() && trim($result->output()) !== '') {
                $pages = explode("\f", $result->output());
                if (end($pages) !== false && trim((string) end($pages)) === '') {
                    array_pop($pages);
                }

                return array_map(fn ($p) => mb_substr(trim((string) $p), 0, 20000), $pages);
            }
        } catch (\Throwable) {
            // Not installed: fall through to Grok.
        }

        if (! GrokCli::configured()) {
            return [];
        }

        try {
            $json = GrokCli::ask(
                'Read manual.pdf in the working folder with read_file. Return the text of every page, in order, '
                .'one string per page, exactly as printed (part numbers character for character). No commentary.',
                ['type' => 'object', 'properties' => ['pages' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    'required' => ['pages'], 'additionalProperties' => false],
                240,
                ['manual.pdf' => (string) file_get_contents($pdf)],
            );
        } catch (\RuntimeException) {
            return [];
        }

        return array_values(array_map(fn ($p) => mb_substr((string) $p, 0, 20000), array_filter((array) ($json['pages'] ?? []), 'is_string')));
    }

    // ── Answers ───────────────────────────────────────────────────

    /** GET /api/v1/ai-assistance/answers?review= */
    public function answers(Request $request): JsonResponse
    {
        $input = $request->validate([
            'review' => ['sometimes', 'nullable', 'string', Rule::in([...AssistantAnswer::REVIEWS, 'unreviewed', 'not_yet'])],
        ]);

        $month = AssistantAnswer::query()->where('created_at', '>=', now()->startOfMonth());

        $rows = AssistantAnswer::query()->with(['user:id,name', 'job'])->latest('created_at')
            ->when(($input['review'] ?? null) === 'unreviewed', fn ($q) => $q->whereNull('review'))
            ->when(($input['review'] ?? null) === 'not_yet', fn ($q) => $q->where('order_check', 'not_yet'))
            ->when(in_array($input['review'] ?? null, AssistantAnswer::REVIEWS, true), fn ($q) => $q->where('review', $input['review']))
            ->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (AssistantAnswer $a) => $this->presentAnswer($a)),
            'summary' => [
                'this_month' => (clone $month)->count(),
                'correct' => AssistantAnswer::query()->where('review', 'correct')->count(),
                'incorrect' => AssistantAnswer::query()->where('review', 'incorrect')->count(),
                'do_not_order' => (clone $month)->where('order_check', 'not_yet')->count(),
            ],
            // Whether the Telegram bot is set up on this server. A boolean only, never the token.
            'telegram_configured' => \App\Support\Telegram\TelegramApi::configured()
                && (string) config('services.telegram.org_id') === (string) $request->user()->organization_id,
        ]);
    }

    /**
     * POST /api/v1/ai-assistance/answers/{answer}/review {review, correction?}
     *
     * "incorrect" with a correction becomes a staff Library entry, linked from
     * the answer, which the assistant reads from then on.
     */
    public function review(Request $request, AssistantAnswer $answer): JsonResponse
    {
        $input = $request->validate([
            'review' => ['required', 'string', Rule::in(AssistantAnswer::REVIEWS)],
            'correction' => ['sometimes', 'nullable', 'string', 'max:'.AssistantEntry::MAX_BODY],
        ]);

        DB::transaction(function () use ($answer, $input, $request) {
            $correction = trim((string) ($input['correction'] ?? ''));
            $answer->fill([
                'review' => $input['review'],
                'reviewed_by' => $request->user()->getKey(),
                'reviewed_at' => now(),
                'correction' => $correction !== '' ? $correction : $answer->correction,
            ]);

            if ($input['review'] === 'incorrect' && $correction !== '') {
                $entry = $answer->correction_entry_id ? AssistantEntry::find($answer->correction_entry_id) : null;
                $title = mb_substr('Fix: '.preg_replace('/\s+/', ' ', $answer->question), 0, AssistantEntry::MAX_TITLE);
                $body = mb_substr("Question: {$answer->question}\nCorrect answer: {$correction}", 0, AssistantEntry::MAX_BODY);
                if ($entry === null) {
                    $entry = AssistantEntry::create([
                        'section' => 'Tech corrections',
                        'title' => $title,
                        'body' => $body,
                        'audience' => AssistantEntry::AUDIENCE_STAFF,
                    ]);
                    $this->audit->log('assistant_entry.created', $entry);
                } else {
                    $entry->update(['body' => $body]);
                }
                $answer->correction_entry_id = $entry->getKey();
            }

            $this->audit->logChange('assistant_answer.reviewed', $answer);
            $answer->save();
        });

        return response()->json(['data' => $this->presentAnswer($answer->fresh(['user:id,name', 'job']))]);
    }

    // ── Shapes ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function partRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $list = ['sometimes', 'nullable', 'array', 'max:20'];

        return [
            'brand' => [$required, 'string', 'max:80'],
            'model' => [$required, 'string', 'max:120'],
            'model_aliases' => $list,
            'model_aliases.*' => ['string', 'max:80'],
            'part_name' => [$required, 'string', 'max:120'],
            'part_aliases' => $list,
            'part_aliases.*' => ['string', 'max:80'],
            'part_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'fuel' => ['sometimes', 'string', Rule::in(AssistantPart::FUELS)],
            'serial_from' => ['sometimes', 'nullable', 'string', 'max:60'],
            'serial_to' => ['sometimes', 'nullable', 'string', 'max:60'],
            'status' => ['sometimes', 'string', Rule::in(AssistantPart::STATUSES)],
            'superseded_by' => ['sometimes', 'nullable', 'string', 'max:60'],
            'source_type' => ['sometimes', 'string', Rule::in(AssistantPart::SOURCES)],
            'source_ref' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, mixed> */
    private function presentPart(AssistantPart $p): array
    {
        return $p->only([
            'id', 'brand', 'model', 'model_aliases', 'part_name', 'part_aliases', 'part_number', 'fuel',
            'serial_from', 'serial_to', 'status', 'superseded_by', 'source_type', 'source_ref', 'notes', 'updated_at',
        ]);
    }

    /** @return array<string, mixed> */
    private function presentManual(AssistantManual $m): array
    {
        return [
            ...$m->only(['id', 'brand', 'models', 'title', 'revision', 'page_count', 'created_at']),
            'readable' => $m->page_count > 0,
        ];
    }

    /** @return array<string, mixed> */
    private function presentAnswer(AssistantAnswer $a): array
    {
        return [
            ...$a->only(['id', 'channel', 'question', 'answer', 'order_check', 'confidence', 'part_ids', 'manual_pages',
                'trace_id', 'review', 'reviewed_at', 'correction', 'correction_entry_id', 'created_at']),
            'user' => $a->user?->name,
            'job' => $a->job?->only(['brand', 'model', 'serial', 'fuel', 'ignition']),
        ];
    }
}
