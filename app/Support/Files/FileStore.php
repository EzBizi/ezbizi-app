<?php

namespace App\Support\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every customer file goes through here (plan §11 "File storage — swappable by
 * design", §9).
 *
 * ── FILESYSTEM_DISK did not move anything, and this is why ───────
 *
 * §11 says to use the storage abstraction "so the backend can move to S3 or
 * Lightsail Object Storage by changing one config value". Two callers shipped
 * passing the literal `'local'` to `store()` and reading back through
 * `Storage::disk('local')->path()`, so the config value decided nothing: the
 * disk was named at four call sites, and `path()` is not part of the abstraction
 * at all. It exists only on the local driver — an S3 disk has no filesystem path
 * to hand `response()->file()`, so the day somebody set FILESYSTEM_DISK=s3 the
 * app would have kept writing to local and then thrown on the first read.
 *
 * So: the disk is read from config, in one place, and files are served by
 * STREAMING them (`readStream`) rather than by asking the driver where they sit
 * on a disk it may not have.
 *
 * ── What moving the disk does and does not do ────────────────────
 *
 * Changing FILESYSTEM_DISK changes where NEW files are written. It does not
 * move the ones already stored. `attachments` carries the disk it was written
 * to on every row for exactly this reason, so a change of default leaves old
 * files readable where they are.
 *
 * `organizations.logo_path`, `companies.logo_path` and `users.avatar_path` do
 * NOT carry one — they are three plain path columns that predate this class, and
 * adding a disk column to three tables is a migration this change did not need
 * to make. The consequence, stated plainly rather than discovered: whoever
 * changes FILESYSTEM_DISK must copy the existing `logos/` and `avatars/` trees
 * across to the new disk first, or every logo and avatar 404s. Attachments need
 * no such step.
 *
 * ── What the backup covers today, and what it does not ───────────
 *
 * This matters more than it looks, so it is written here rather than assumed.
 *
 * The nightly backup covers the DATABASE, the git tree and `.env`. It does not
 * cover `storage/`. Every file this class writes on the `local` disk therefore
 * lives on the instance's own volume and nowhere else: a rebuild of the box, or
 * a lost volume, takes every logo, every avatar and — once contracts land —
 * every signed PDF with it, while the database keeps a row confidently pointing
 * at each one.
 *
 * §11 names this cost ("files do not survive a rebuild of the instance unless
 * separately backed up") and judges it acceptable at current scale, which it was
 * while the only files were logos anybody could re-upload. It stops being
 * acceptable the moment the files ARE the record — a signed contract is not
 * re-creatable, and a scan of somebody's licence is not something to ask them
 * for twice. Either the backup grows to include `storage/`, or the default disk
 * moves to object storage that is durable on its own. Whoever picks up
 * Contracts owns that decision; this docblock exists so it is a decision rather
 * than a discovery.
 */
class FileStore
{
    /**
     * The one place a disk is chosen.
     *
     * Read every time rather than memoised: `config()` is already an in-memory
     * array, and a cached copy here would be one more thing that disagrees with
     * the environment after a `config:cache`.
     */
    public function defaultDisk(): string
    {
        $disk = config('filesystems.default');

        if (! is_string($disk) || $disk === '') {
            throw new RuntimeException(
                'filesystems.default names no disk. Every customer file in this app is '
                .'written through it, so an empty value is not a default worth guessing at.'
            );
        }

        return $disk;
    }

    /**
     * Where a kind of file lives for one organization.
     *
     * §11: "Store files under a per-organization prefix from day one — that's
     * what makes GDPR erasure a single delete instead of a hunt." The kind comes
     * first and the organization second, matching the two prefixes already on
     * disk (`logos/`, `avatars/`), so erasing an organization is one prefix
     * delete per kind rather than a walk of the whole tree — and composing it
     * here means the convention cannot be spelt three ways by three callers.
     */
    public function directoryFor(string $kind, string $organizationId): string
    {
        return "{$kind}/{$organizationId}";
    }

    /**
     * Store an upload and answer the path it landed at.
     *
     * The disk is NOT part of the path and is returned separately by
     * `defaultDisk()`, because a path that carried its disk would be a path that
     * breaks when the disk is renamed.
     */
    public function put(UploadedFile $file, string $directory, ?string $disk = null): string
    {
        $path = $file->store($directory, $disk ?? $this->defaultDisk());

        if ($path === false) {
            throw new RuntimeException(
                "Storing an upload under '{$directory}' failed. The disks are configured "
                .'with `throw => false`, so a driver problem answers false rather than '
                .'raising — and a caller that took that for a path would write a row '
                .'pointing at nothing.'
            );
        }

        return $path;
    }

    /**
     * Store bytes the SERVER produced, and answer the path they landed at.
     *
     * `put()` above takes an UploadedFile, which is the only kind of file this
     * app had until a contract document had to be rendered. A generated artefact
     * has no temporary upload behind it, and the alternatives were both worse
     * than one more method: writing it to a temp file and wrapping it in an
     * UploadedFile is a round trip through the filesystem to satisfy a type, and
     * reaching for `Storage::disk()` at the call site is precisely the habit this
     * class exists to remove — see the note above about FILESYSTEM_DISK deciding
     * nothing while four call sites named their own disk.
     *
     * The extension is the caller's, because only the caller knows what it made.
     * The NAME is not: it is random, like the framework's own, so nothing about
     * a stored file's path can be predicted or influenced.
     */
    public function putContents(
        string $contents,
        string $directory,
        string $extension,
        ?string $disk = null,
    ): string {
        $path = trim($directory, '/').'/'.Str::random(40).'.'.ltrim($extension, '.');

        if ($this->on($disk)->put($path, $contents) === false) {
            throw new RuntimeException(
                "Writing a generated file to '{$path}' failed. The disks are configured "
                .'with `throw => false`, so a driver problem answers false rather than '
                .'raising — and a caller that took that for success would write a row '
                .'pointing at nothing.'
            );
        }

        return $path;
    }

    /**
     * Read a stored file back into memory.
     *
     * For files the application itself has to look at rather than hand to a
     * browser — a signed document being fingerprinted, which has to be hashed as
     * the bytes that are actually on the disk rather than as the bytes somebody
     * believes were written there. Null when it is not there, so a caller can
     * tell "gone" from "empty" without an exception.
     */
    public function contents(string $path, ?string $disk = null): ?string
    {
        $contents = $this->on($disk)->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        return $this->on($disk)->exists($path);
    }

    /** Missing is not an error: deleting a file twice must not be one either. */
    public function delete(?string $path, ?string $disk = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->on($disk)->delete($path);
    }

    /**
     * Send a stored file to a caller who has already been authorized.
     *
     * ── Streamed, not `response()->file()` ───────────────────────
     *
     * `file()` needs a local filesystem path, which is precisely the thing an
     * object-store disk cannot give. `readStream()` is on the interface, works
     * on every driver, and does not pull the whole file into memory on the way
     * past — which starts to matter the first time somebody attaches a video.
     *
     * ── Two headers that are not decoration ──────────────────────
     *
     * `X-Content-Type-Options: nosniff` — the bytes came from a customer, and a
     * browser that sniffs its way from the declared type to `text/html` runs
     * whatever the file contains, on this app's origin, with this app's cookies.
     *
     * `Cache-Control: private, no-store` — these responses are behind an
     * authorization check, and a shared cache holding one would serve somebody's
     * licence to the next person through the proxy.
     *
     * ── Disposition is the caller's decision, and a real one ─────
     *
     * `inline` tells the browser to render the bytes. That is right for a logo
     * the app validated the dimensions of, and wrong for an arbitrary upload:
     * combined with a mime the uploader influenced, it is a stored-XSS delivery
     * mechanism. Callers serving customer uploads pass `attachment`; the two
     * image endpoints that predate this class pass `inline` because they always
     * did and the files behind them are validated images.
     */
    public function serve(
        string $path,
        ?string $disk = null,
        ?string $mime = null,
        ?string $filename = null,
        string $disposition = 'attachment',
    ): StreamedResponse {
        $storage = $this->on($disk);

        /*
         * Opened HERE, not inside the callback.
         *
         * A streamed response runs its body after the headers have gone, so a
         * missing file discovered in there can only produce a 200 with an empty
         * body or an exception mid-stream — both of which read as "the file is
         * empty" rather than "the file is not there". Opening first means the
         * one 404 this method can answer is answered before anything is sent.
         *
         * It also removes the exists()-then-read race: a file deleted between
         * the two calls used to be a 200 with nothing in it.
         */
        $stream = $storage->readStream($path);

        abort_if($stream === null || $stream === false, 404);

        $headers = [
            'Content-Type'           => $mime ?: ($storage->mimeType($path) ?: 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ];

        // Not every driver can answer, and a WRONG Content-Length is worse than
        // none — a browser waiting for bytes that are not coming looks like the
        // server hanging.
        $size = $storage->size($path);

        if (is_int($size)) {
            $headers['Content-Length'] = (string) $size;
        }

        return response()->streamDownload(
            function () use ($stream) {
                // `stream_copy_to_stream` into an explicit php://output handle,
                // not `fpassthru`. The latter writes to the SAPI's output
                // directly, which is the same thing in production and not the
                // same thing under a test harness that captures output by
                // buffering — there, fpassthru's bytes go past the buffer and
                // the assertion sees an empty body for a response that is
                // perfectly correct in the browser. Exporter writes through
                // php://output for the same reason.
                $out = fopen('php://output', 'wb');

                stream_copy_to_stream($stream, $out);

                fclose($stream);
                fclose($out);
            },
            $this->safeDownloadName($filename ?? basename($path)),
            $headers,
            $disposition,
        );
    }

    /**
     * A Content-Disposition filename cannot carry CR, LF, quotes or a path.
     * The original name is already truncated to the column; this is the header.
     */
    private function safeDownloadName(string $filename): string
    {
        $name = str_replace(["\r", "\n", '"', '\\'], '', $filename);
        $name = basename($name);

        return $name === '' ? 'download' : $name;
    }

    private function on(?string $disk = null): Filesystem
    {
        return Storage::disk($disk ?? $this->defaultDisk());
    }
}
