<?php

namespace App\Support\Files;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * How big a file may be, and what it may be — said in a sentence somebody can
 * act on.
 *
 * ── The failure this exists to prevent ───────────────────────────
 *
 * PHP enforces `upload_max_filesize` and `post_max_size` in the SAPI, before a
 * single line of this application runs, and neither of them produces a message
 * anybody can act on.
 *
 * A file over `upload_max_filesize` arrives as an upload that is PRESENT and
 * invalid: `$request->file()` hands back an UploadedFile carrying
 * `UPLOAD_ERR_INI_SIZE` and no bytes. Laravel's `file` rule then says "The file
 * failed to upload", which is true and useless — somebody who just tried a 40MB
 * scan is told the transfer broke, so they try again, and are told it broke
 * again.
 *
 * A body over `post_max_size` never gets that far: PHP discards it entirely, and
 * Laravel's own ValidatePostSize middleware answers 413 before this controller
 * runs. Nothing here can improve on that, and this class does not pretend to —
 * what it does is make it unreachable in practice, by keeping the enforced limit
 * under the PHP ceiling so the VALIDATOR is what refuses, with a number in the
 * sentence.
 *
 * So two things, in this order:
 *
 *   1. `maxKilobytes()` sits below PHP's own limits, so an oversized upload is
 *      normally refused by the validator with a readable message.
 *   2. `assertUploadArrived()` catches the one that still gets past — a file
 *      between the two limits, which reaches the request broken rather than
 *      large — and gives it the same sentence.
 *
 * ── Why the numbers are config and not constants ─────────────────
 *
 * §2.1 category 2: a limit is a fact about where this copy is installed. The
 * PHP ceiling differs between the app box and a developer's machine, and the
 * tenant-facing number has to sit under whichever one is in force. Neither
 * belongs in code.
 */
class UploadLimits
{
    /**
     * Headroom under the PHP ceiling, in kilobytes.
     *
     * A multipart body is the file plus its boundaries, the other form fields
     * and the headers, so a file exactly at `post_max_size` produces a body
     * over it. 256KB is far more than that overhead ever is and costs nothing.
     */
    private const HEADROOM_KILOBYTES = 256;

    /**
     * The largest file this installation accepts, in kilobytes.
     *
     * `min` of what the tenant-facing config asks for and what PHP will actually
     * carry. Configuring 100MB on a box whose `post_max_size` is 8M does not
     * give anybody 100MB — it gives them a confusing error at 8M — so the number
     * quoted in the message is the one that will really be enforced.
     */
    public function maxKilobytes(): int
    {
        $configured = (int) config('ezbizi.attachments.max_kilobytes');
        $ceiling    = $this->phpCeilingKilobytes() - self::HEADROOM_KILOBYTES;

        return max(1, min($configured, $ceiling));
    }

    /** @return string[] the extensions this installation accepts */
    public function extensions(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            (array) config('ezbizi.attachments.extensions', []),
        )));
    }

    /** @return array<string, string[]> validation rules for an upload field */
    public function rulesFor(string $field): array
    {
        return [$field => [
            'required', 'file',
            // `mimes` checks the extension AGAINST the detected content type, so
            // renaming a script to .pdf does not get it in. `mimetypes` alone
            // would let a .html through under text/plain.
            'mimes:'.implode(',', $this->extensions()),
            'max:'.$this->maxKilobytes(),
        ]];
    }

    /**
     * Messages that name the actual limit.
     *
     * Laravel's default is "The file may not be greater than 20480 kilobytes",
     * which is both a unit nobody thinks in and a number nobody can compare to
     * what their file manager told them.
     *
     * @return array<string, string>
     */
    public function messagesFor(string $field): array
    {
        return [
            "{$field}.max"   => $this->tooLargeMessage(),
            "{$field}.mimes" => sprintf(
                'That kind of file is not accepted here. This installation takes: %s.',
                implode(', ', $this->extensions()),
            ),
        ];
    }

    public function tooLargeMessage(): string
    {
        return sprintf(
            'That file is too large. The most this installation accepts is %s — compress it, '
            .'or split it into more than one attachment.',
            $this->humanMax(),
        );
    }

    /** `20 MB`, or `700 KB` where the limit is small enough that MB reads as 0.7. */
    public function humanMax(): string
    {
        $kb = $this->maxKilobytes();

        return $kb >= 1024
            ? rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.').' MB'
            : $kb.' KB';
    }

    /**
     * The upload PHP refused before the validator could weigh it.
     *
     * Between `upload_max_filesize` and `post_max_size` there is a band where
     * the body arrives but the file does not: PHP fills in
     * `$_FILES[...]['error']` and leaves `tmp_name` empty, so the request has an
     * UploadedFile object with nothing behind it. `max:` never runs, because
     * there is no size to compare, and the caller is told the transfer failed.
     *
     * Only the two size errors are translated. `UPLOAD_ERR_PARTIAL` and the rest
     * really are broken transfers and really should say so — claiming a
     * connection drop was a size limit would send somebody off compressing a
     * file that was never too big.
     *
     * Deliberately a 422 on the same field the validator would have used, so the
     * client shows it in the same place as every other refusal about that field
     * rather than needing a second code path for the large one.
     *
     * @throws ValidationException
     */
    public function assertUploadArrived(Request $request, string $field): void
    {
        $file = $request->file($field);

        // No file at all is a different problem, and `required` words it better
        // than anything this method could say.
        if (! $file instanceof UploadedFile || $file->isValid()) {
            return;
        }

        if (! in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return;
        }

        throw ValidationException::withMessages([$field => $this->tooLargeMessage()]);
    }

    /**
     * What PHP itself will carry, in kilobytes — the lower of its two limits.
     *
     * Both apply and they are independently configured: `upload_max_filesize`
     * caps the file, `post_max_size` caps the whole body. Whichever is smaller
     * is the real ceiling, and reading only one of them is how a limit that
     * looks generous turns out not to be.
     *
     * `post_max_size = 0` means unlimited, which is a legitimate configuration
     * and must not be read as "zero bytes" — that would clamp every upload to
     * the 1KB floor and refuse everything.
     */
    private function phpCeilingKilobytes(): int
    {
        $limits = array_filter([
            $this->iniKilobytes('upload_max_filesize'),
            $this->iniKilobytes('post_max_size'),
        ], fn (?int $value) => $value !== null && $value > 0);

        return $limits === [] ? PHP_INT_MAX : (int) min($limits);
    }

    /** `8M` → 8192. Null when the directive is absent or unparseable. */
    private function iniKilobytes(string $directive): ?int
    {
        $raw = trim((string) ini_get($directive));

        if ($raw === '') {
            return null;
        }

        $value = (int) $raw;
        $unit  = strtolower(substr($raw, -1));

        return match ($unit) {
            'g'     => $value * 1024 * 1024,
            'm'     => $value * 1024,
            'k'     => $value,
            // A bare byte count, which is legal in php.ini and rounds up so a
            // sub-kilobyte limit does not become zero.
            default => is_numeric($raw) ? (int) ceil($value / 1024) : null,
        };
    }
}
