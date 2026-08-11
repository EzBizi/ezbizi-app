<?php

namespace App\Services\Hr;

use App\Models\Attachment;
use App\Models\Onboarding;
use App\Models\OnboardingDocument;
use App\Models\User;
use App\Support\Contracts\DocumentFingerprint;
use App\Support\Contracts\DocumentRenderer;
use App\Support\Files\FileStore;
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
 */
class OnboardingDocuments
{
    public function __construct(
        private readonly DocumentRenderer $renderer,
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

        return $this->files->contents($attachment->path, $attachment->disk);
    }

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
     * What the file is called when somebody downloads it.
     *
     * Built from the contract's own name and the two numbers that identify the
     * document — the version of the template it came from and which revision of
     * this person's paperwork it is. A folder of these has to be sortable and
     * readable by somebody who is not looking at the app.
     *
     * `Str::slug` because the name is tenant text and this ends up in a
     * Content-Disposition header. It is not the stored path — that is random,
     * and FileStore keeps it that way — so a collision here costs nothing.
     */
    private function filenameFor(Onboarding $onboarding, int $revision): string
    {
        $version = $onboarding->contractVersion;
        $slug    = Str::slug((string) $version->contract?->name) ?: 'contract';

        return sprintf(
            '%s-v%d-r%d.%s',
            $slug, (int) $version->version, $revision, DocumentRenderer::EXTENSION,
        );
    }
}
