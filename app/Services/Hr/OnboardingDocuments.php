<?php

namespace App\Services\Hr;

use App\Models\Attachment;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\OnboardingDocument;
use App\Models\User;
use App\Support\Contracts\DocumentFingerprint;
use App\Support\Contracts\DocumentRenderer;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\PdfRenderer;
use App\Support\Files\FileStore;
use App\Support\Privacy\SensitiveValues;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Producing, storing and fingerprinting the contract document.
 *
 * ── The order of operations IS the guarantee ─────────────────────
 *
 * The plan's rule: "What is NOT acceptable is a fingerprint over one
 * representation and a document stored in another, because then the hash proves
 * nothing about the file." So:
 *
 *   1. render
 *   2. write the bytes to the disk
 *   3. READ THEM BACK
 *   4. fingerprint what came back
 *   5. write the row
 *
 * Step 3 is the one that looks redundant and is not. Hashing the string in
 * memory would fingerprint what the application MEANT to store; hashing the
 * file fingerprints what is actually there, which is the thing somebody will one
 * day download and compare. The two differ the day a driver transforms
 * something on the way past — a trailing newline, an encoding, a compression
 * layer — and that is exactly the day the hash needed to be right.
 *
 * Everything downstream depends on this: a signature's `content_hash` is the
 * document's `content_hash`, and both are the hash of the stored file.
 *
 * ── The bytes go through the attachment primitive ────────────────
 *
 * §11's `attachments` table, not a path column of its own. That buys the disk
 * being remembered per file (so changing FILESYSTEM_DISK leaves old documents
 * readable), the checksum, the per-organization prefix that makes erasure one
 * delete, the authorization that comes from the record the file hangs off, and
 * the delete hook that takes the bytes with the record. Every one of those would
 * have had to be rebuilt here.
 *
 * ── One document is stored. The PDF copy is not one of them ──────
 *
 * `pdfCopyOf()` converts a stored revision into a PDF and hands it straight to
 * whoever asked. It is not written to the disk, has no attachment and has no
 * row, and that is the design rather than a step left out: storing it would
 * make a second artefact of the same agreement, and the moment there are two
 * the question "which one did they sign" has an answer nobody can read off the
 * records. The copy is reproducible from the stored document whenever it is
 * wanted, and its own fingerprint is recorded where a download is recorded.
 */
class OnboardingDocuments
{
    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly PdfRenderer $pdf,
        private readonly OnboardingForm $form,
        private readonly FileStore $files,
    ) {}

    /**
     * The document as it would be rendered right now.
     *
     * What the signing screen shows before anything is stored, and therefore the
     * bytes whose hash the signer sends back with their signature. Deterministic
     * — see DocumentRenderer — so "the preview I read" and "the document that was
     * stored" are the same bytes unless something genuinely changed in between,
     * which is the case the hash check is there to catch.
     */
    public function render(Onboarding $onboarding): string
    {
        return $this->renderer->render(
            $onboarding,
            $this->form->identity($onboarding),
            $onboarding->answers()->get(),
        );
    }

    /**
     * The document as it would read with somebody's unsaved typing in it, for
     * the contract shown beside the form (Jose, 2026-10-01). Writes nothing.
     *
     * A saved sensitive answer is masked unless the caller is typing over it:
     * this is the form, not a reveal, so it must not show more than the form.
     *
     * @param  array<string, mixed>  $draft  field key => unsaved value
     */
    public function preview(Onboarding $onboarding, array $draft, array $highlight = []): string
    {
        // Not a question: the company address typed on the form, in memory only.
        if (array_key_exists(Onboarding::EMPLOYER_ADDRESS, $draft)) {
            $onboarding->employer_address = trim((string) $draft[Onboarding::EMPLOYER_ADDRESS]) ?: null;
        }

        $answers = $onboarding->answers()->get()->keyBy('field_key');

        foreach ($onboarding->fields() as $field) {
            $key = $field->key;
            if (array_key_exists($key, $draft) && is_scalar($draft[$key])) {
                $answer = $answers->get($key) ?? new OnboardingAnswer(['field_key' => $key]);
                $answer->value = (string) $draft[$key];
                $answers->put($key, $answer);
            } elseif ($field->sensitive && $answers->has($key)) {
                $answers->get($key)->value = SensitiveValues::MASK;
            }
        }

        $identity = $this->form->identity($onboarding);
        // The address may come in its parts, as the form saves it.
        if (is_array($draft['address'] ?? null) && $onboarding->user !== null) {
            $draft['address'] = User::composeAddress(array_replace(
                $onboarding->user->addressParts(),
                array_intersect_key($draft['address'], array_flip(User::ADDRESS_PARTS)),
            ));
        }
        foreach ($identity as $key => $value) {
            if (array_key_exists($key, $draft) && is_scalar($draft[$key])) {
                $identity[$key] = (string) $draft[$key];
            }
        }

        return $this->renderer->render($onboarding, $identity, $answers->values(), $highlight);
    }

    /**
     * The signature somebody drew, kept as a PNG on the onboarding for the
     * signature block of the printed contract (Jose, 2026-10-01).
     */
    public function storeDrawing(Onboarding $onboarding, User $actor, string $png): Attachment
    {
        return $this->storeFile($onboarding, $actor, $png, 'signature.png', 'image/png', 'onboarding-signatures');
    }

    /** Bytes this server made (a drawn signature, a signed tax form), kept on the onboarding. */
    public function storeFile(
        Onboarding $onboarding,
        User $actor,
        string $bytes,
        string $name,
        string $mime,
        string $folder = 'onboarding-files',
    ): Attachment {
        $path = $this->files->putContents(
            $bytes,
            $this->files->directoryFor($folder, $onboarding->organization_id),
            pathinfo($name, PATHINFO_EXTENSION) ?: 'bin',
        );

        $attachment = new Attachment([
            'attachable_type' => 'onboardings',
            'attachable_id' => $onboarding->getKey(),
            'original_name' => $name,
            'mime' => $mime,
            'uploaded_by_user_id' => $actor->getKey(),
        ]);
        $attachment->forceFill([
            'disk' => $this->files->defaultDisk(),
            'path' => $path,
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ])->save();

        return $attachment;
    }

    /**
     * A drawn signature as sent: a PNG data URL or bare base64. Null when it
     * is not a PNG of a sensible size.
     */
    public static function drawingBytes(?string $sent): ?string
    {
        if ($sent === null || trim($sent) === '') {
            return null;
        }
        $data = preg_replace('#^data:image/png;base64,#', '', trim($sent));
        $png = base64_decode((string) $data, true);

        return is_string($png) && strlen($png) <= 400_000 && str_starts_with($png, "\x89PNG\r\n\x1a\n")
            ? $png
            : null;
    }

    /** The same draft as preview(), as the PDF it would print as. */
    public function previewPdf(Onboarding $onboarding, array $draft): string
    {
        return $this->pdf->draft(
            $this->preview($onboarding, $draft),
            (string) $onboarding->contractVersion?->contract?->name,
        );
    }

    /**
     * The bytes of a stored revision, as they are on the disk.
     *
     * Null when the file has gone. That is a real possibility and not a
     * theoretical one: `storage/` is outside the nightly backup (see FileStore),
     * so a rebuilt instance has the rows and not the files. Callers answer 404
     * rather than pretending the document is empty.
     */
    public function contentsOf(OnboardingDocument $document): ?string
    {
        $attachment = $document->attachment;

        if ($attachment === null) {
            return null;
        }

        return $attachment->bytes();
    }

    /**
     * The signed contract an import brought as a PDF (A1 Tools' own,
     * `signed_contract`), for an onboarding with no document of its own: its
     * download (Jose, 2026-10-05: "i can download the new onboarding but not
     * the imported old ones"). Null when there is none.
     */
    public function importedPdf(Onboarding $onboarding): ?string
    {
        $id = $onboarding->answers()->where('field_key', self::IMPORTED_CONTRACT)->first()?->value;
        $attachment = $id === null ? null : Attachment::query()->find($id);

        return $attachment?->mime === PdfRenderer::MIME
            ? $attachment->bytes()
            : null;
    }

    /** The file name of an I-9 with both sections signed. */
    public const I9_COMPLETE = 'I-9 (Sections 1 and 2).png';

    /** A stored file's bytes, or null when it has gone. */
    public function fileBytes(Attachment $file): ?string
    {
        return $file->bytes();
    }

    /** The question an import files its signed contract PDF under. */
    public const IMPORTED_CONTRACT = 'signed_contract';

    /**
     * Render, store and fingerprint one revision.
     *
     * @param  string[]  $changedKeys  which fields this revision corrects; empty on the original
     */
    public function store(
        Onboarding $onboarding,
        User $actor,
        int $revision,
        ?string $reason = null,
        array $changedKeys = [],
    ): OnboardingDocument {
        $content = $this->render($onboarding);

        $path = $this->files->putContents(
            $content,
            // The same prefix scheme every other file uses, so erasing an
            // organization stays one delete per kind rather than a walk.
            $this->files->directoryFor('onboarding-documents', $onboarding->organization_id),
            DocumentRenderer::EXTENSION,
        );

        $disk   = $this->files->defaultDisk();
        $stored = $this->files->contents($path, $disk);

        if ($stored === null) {
            throw new RuntimeException(
                'The contract document was written and could not be read back, so there is '
                .'nothing to fingerprint. Refusing to record a signature against a document '
                .'this server cannot produce.'
            );
        }

        $attachment = new Attachment([
            'attachable_type' => 'onboardings',
            'attachable_id'   => $onboarding->getKey(),
            'original_name'   => $this->filenameFor($onboarding, $revision),
            'mime'            => DocumentRenderer::MIME,
            'uploaded_by_user_id' => $actor->getKey(),
        ]);

        $attachment->forceFill([
            'disk'       => $disk,
            'path'       => $path,
            'size_bytes' => strlen($stored),
            'checksum'   => hash('sha256', $stored),
        ])->save();

        return OnboardingDocument::create([
            'onboarding_id'  => $onboarding->getKey(),
            'attachment_id'  => $attachment->getKey(),
            'revision'       => $revision,
            'reason'         => $reason,
            'changed_keys'   => $changedKeys === [] ? null : array_values($changedKeys),
            // Of the STORED bytes. See the class docblock for why that sentence
            // is the whole point of this method.
            'content_hash'   => DocumentFingerprint::of($stored),
            'hash_algorithm' => DocumentFingerprint::ALGORITHM,
            'content_length' => strlen($stored),
            'created_by'     => $actor->getKey(),
        ]);
    }

    /**
     * A PDF COPY of one stored revision, converted from bytes the caller has
     * already read back off the disk.
     *
     * ── Why the bytes are a PARAMETER and not read in here ───────
     *
     * It looks like a method that should fetch its own input, and it must not.
     * The caller reads the stored file, checks it still matches the fingerprint
     * this revision recorded, and hands THOSE BYTES here. A second read inside
     * this method would be a second answer from the disk between the check and
     * the conversion — so the thing verified and the thing converted would be
     * two reads, which is the same shape as the "two artefacts" failure the whole
     * document design is built to avoid.
     *
     * It is also what keeps the copy honest about where it came from. Nothing in
     * this method can reach DocumentRenderer; a PDF produced here is a conversion
     * of stored bytes or it is nothing.
     */
    public function pdfCopyOf(
        Onboarding $onboarding,
        OnboardingDocument $document,
        string $storedDocument,
    ): string {
        $version = $onboarding->contractVersion;

        return $this->pdf->render(
            storedDocument:     $storedDocument,
            organizationName:   (string) $onboarding->organization->name,
            contractName:       (string) $version->contract?->name,
            version:            (int) $version->version,
            revision:           (int) $document->revision,
            sourceFingerprint:  (string) $document->content_hash,
            sourceAlgorithm:    (string) $document->hash_algorithm,
            // When the SOURCE was stored, so that two conversions of one document
            // are one file — see PdfRenderer on why the copy is not dated now().
            // `created_at` is written once and never updated on this model.
            sourceStoredAt: $document->created_at ?? $onboarding->started_at ?? now(),
            signatures: $this->signatureBlocks($onboarding),
            attachments: $this->attachmentPages($onboarding),
            // A contract with no wording prints every answer already.
            record: trim((string) $version->body) === '' ? [] : $this->recordRows($onboarding, (string) $version->body),
        );
    }

    /**
     * Every answer and profile detail the contract's wording does not merge,
     * as label and value (Jose, 2026-10-05: "stuff that is not explicitly in
     * the contract written"). Files are not rows: they are the attachments.
     *
     * @return list<array{label: string, value: string}>
     */
    public function recordRows(Onboarding $onboarding, string $body = ''): array
    {
        $mentioned = fn (string $key) => preg_match(
            '/\{\{\s*[#\/^]?\s*'.preg_quote($key, '/').'\s*\}\}/', $body,
        ) === 1;
        $rows = [];

        foreach ($this->form->identity($onboarding) as $key => $value) {
            if (trim((string) $value) !== '' && ! $mentioned($key)) {
                $rows[] = ['label' => \App\Support\Contracts\CommonCore::labelFor($key), 'value' => trim((string) $value)];
            }
        }

        $answers = $onboarding->answers()->get()->keyBy('field_key');
        foreach ($onboarding->fields() as $field) {
            if (in_array($field->type, [FieldType::SIGNATURE, FieldType::FILE], true)
                || $field->retired || $mentioned($field->key)) {
                continue;
            }
            $value = trim((string) ($answers->get($field->key)?->value ?? ''));
            if ($value === '') {
                continue;
            }
            $rows[] = ['label' => $field->label, 'value' => match (true) {
                $value === \App\Support\Contracts\MergeValue::NOT_APPLICABLE => 'Does not apply',
                $field->type === FieldType::CHECKLIST => implode("\n", array_map(
                    fn (string $item, array $row) => trim($item.'  ×'.$row['quantity'].'  '.$row['received']),
                    array_keys($items = \App\Support\Contracts\ChecklistTable::rows($value)), $items,
                )),
                default => $value,
            }];
        }

        return $rows;
    }

    /**
     * The download of a contract signed elsewhere and imported: its original
     * PDF, then the record and the uploads (Jose, 2026-10-05). The pages are
     * joined with qpdf; without it on the server the original goes alone.
     *
     * ponytail: qpdf on the host, because the A1 PDFs use xref streams no
     * free PHP parser reads; a pure-PHP merge if qpdf ever cannot be had.
     */
    public function importedWithRecord(Onboarding $onboarding, ?string $original): ?string
    {
        $record = $this->recordRows($onboarding);
        // Pictures are drawn into the record; other PDFs they uploaded (a W-4)
        // are joined after it, whole.
        $attachments = array_values(array_filter(
            $this->attachmentPages($onboarding),
            fn (array $page) => $page['mime'] !== PdfRenderer::MIME,
        ));
        $pdfs = [];
        $answers = $onboarding->answers()->get()->keyBy('field_key');
        foreach ($onboarding->fields() as $field) {
            $id = $field->type === FieldType::FILE && $field->key !== self::IMPORTED_CONTRACT
                ? (string) ($answers->get($field->key)?->value ?? '') : '';
            $file = $id === '' ? null : Attachment::query()->find($id);
            if ($file?->mime === PdfRenderer::MIME && ($bytes = $file->bytes()) !== null) {
                $pdfs[] = $bytes;
            }
        }
        if ($record === [] && $attachments === [] && $pdfs === []) {
            return $original;
        }

        $extra = $this->pdf->recordOnly(
            trim(($onboarding->user?->name ?? '').' · Employee record', ' ·'),
            $record,
            $attachments,
        );

        // Imported with no signed contract: the record is the whole download.
        $parts = array_values(array_filter([$original, $extra, ...$pdfs], fn ($p) => $p !== null));
        if (count($parts) === 1) {
            return $extra;
        }

        $dir = sys_get_temp_dir().'/ezbizi-record-'.Str::uuid();
        try {
            // The system temp dir: a release's storage/app/tmp could not be
            // made on the box, and the record was silently left off (Jose,
            // 2026-10-05: "it just shows the contract").
            if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
                throw new RuntimeException("Could not make {$dir} to join the record to the imported contract.");
            }
            $files = [];
            foreach ($parts as $i => $bytes) {
                file_put_contents($files[] = "{$dir}/{$i}.pdf", $bytes);
            }
            $run = \Illuminate\Support\Facades\Process::run(['qpdf', '--empty', '--pages', ...$files, '--', "{$dir}/out.pdf"]);

            // qpdf exits 3 when it succeeded with warnings, which a PDF made
            // elsewhere often earns.
            if (in_array($run->exitCode(), [0, 3], true) && is_file("{$dir}/out.pdf")) {
                return (string) file_get_contents("{$dir}/out.pdf");
            }
            throw new RuntimeException('qpdf could not join the record: exit '.$run->exitCode().' '.mb_substr($run->errorOutput(), 0, 300));
        } catch (\Throwable $e) {
            report($e);

            return $original ?? $extra;
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * Every file answered on the form, for the copy's Attachments pages: the
     * question, the file's name, and the picture itself when it is one the
     * converter can draw (JPEG, PNG, GIF). Anything else is named only.
     *
     * @return list<array{label: string, name: string, mime: string, data: ?string}>
     */
    private function attachmentPages(Onboarding $onboarding): array
    {
        $answers = $onboarding->answers()->get()->keyBy('field_key');
        $pages = [];

        foreach ($onboarding->fields() as $field) {
            if ($field->type !== \App\Support\Contracts\FieldType::FILE) {
                continue;
            }

            $id = (string) ($answers->get($field->key)?->value ?? '');
            $file = $id === '' ? null : Attachment::query()->whereKey($id)->first();

            if ($file === null) {
                continue;
            }

            $mime = (string) $file->mime;
            $drawable = in_array($mime, ['image/jpeg', 'image/png'], true);
            $bytes = $drawable ? $this->files->contents($file->path, $file->disk) : null;

            $pages[] = [
                'label' => (string) $field->label,
                'name' => (string) $file->original_name,
                'mime' => $mime,
                'data' => $bytes === null ? null : base64_encode($bytes),
            ];
        }

        return $pages;
    }

    /**
     * What fills each signature block of the printed copy: the drawn
     * signature, the name they typed, the day in the organization's zone.
     *
     * @return array<string, array{name: string, date: string, png: ?string}>
     */
    private function signatureBlocks(Onboarding $onboarding): array
    {
        $zone = (string) (app(\App\Support\Settings\SettingsResolver::class)
            ->get('timezone', $onboarding->organization) ?: config('app.timezone'));
        $blocks = [];

        foreach ($onboarding->takenSignatures() as $signature) {
            $drawing = $signature->drawing_attachment_id === null ? null
                : Attachment::query()->whereKey($signature->drawing_attachment_id)->first();
            $png = $drawing === null ? null : $this->files->contents($drawing->path, $drawing->disk);

            $blocks[$signature->field_key] = [
                'name' => (string) $signature->affirmation,
                'date' => $signature->signed_at?->copy()->timezone($zone)->format('F j, Y') ?? '',
                'png' => $png === null ? null : base64_encode($png),
            ];
        }

        return $blocks;
    }

    /**
     * What the file is called when somebody downloads it.
     *
     * Built from the contract's own name and the two numbers that identify the
     * document — the version of the template it came from and which revision of
     * this person's paperwork it is. A folder of these has to be sortable and
     * readable by somebody who is not looking at the app.
     *
     * The EXTENSION is a parameter because two files come out of one revision:
     * the stored document, and the PDF copy of it. They are deliberately the same
     * name apart from that, so a folder holding both shows at a glance which copy
     * belongs to which document.
     *
     * `Str::slug` because the name is tenant text and this ends up in a
     * Content-Disposition header. It is not the stored path — that is random,
     * and FileStore keeps it that way — so a collision here costs nothing.
     */
    public function filenameFor(
        Onboarding $onboarding,
        int $revision,
        string $extension = DocumentRenderer::EXTENSION,
    ): string {
        $version = $onboarding->contractVersion;
        $slug    = Str::slug((string) $version->contract?->name) ?: 'contract';

        return sprintf(
            '%s-v%d-r%d.%s',
            $slug, (int) $version->version, $revision, $extension,
        );
    }
}
