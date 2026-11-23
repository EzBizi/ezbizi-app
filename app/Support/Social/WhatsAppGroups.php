<?php

namespace App\Support\Social;

use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppPendingReport;
use App\Support\Reports\Texts\ReportPdf;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Delivering a report to "the NJ daily group" (docs/REPLACING-RODRIGO-BOT.md).
 *
 * ── Two ways, one call ───────────────────────────────────────────
 *
 * A group with a `remote_id` is a real WhatsApp group made with Meta's Groups
 * API, and gets one message. That needs an Official Business Account (the
 * earliest A-1's number can ask is ~2026-10-15), so until then the same call
 * sends the report to each MEMBER one to one (toPerson), whether or not they
 * wrote in the last 24 hours. The sender never knows which happened.
 *
 * Either way a report is ONE message: its formatted PDF, the report's text
 * as the caption (Jose, 2026-11-10: "Why can't it be just ONE message, just
 * the message + the attachment").
 */
final class WhatsAppGroups
{
    /** A free-form text's pieces: WhatsApp refuses a text over 4096 characters. */
    private const TEXT_LIMIT = 4000;

    /** A template value's pieces: the whole body may be 1024, the template's own words included. */
    private const VALUE_LIMIT = 800;

    /** WhatsApp's limit on a document's caption. */
    private const CAPTION_LIMIT = 1024;

    /** A report that goes in the template as its value: run on, " | " between lines adds up to two characters a line, so it stays within VALUE_LIMIT. */
    private const TEMPLATE_REPORT = 640;

    /**
     * The line templates (report_lines_N): N values, {{1}} the title, {{2}} its date, one a line after.
     * Meta wants twice as many fixed words as values, so their own words take ~230 of the body's 1024
     * characters; LINE_ROOM is what the values may fill, with room for emoji Meta counts as two.
     */
    private const LINE_TEMPLATE = 'report_lines_';

    private const LINE_ROOM = 740;

    /** A blank line as a value: Meta refuses an empty one, and Braille's blank shows as nothing. */
    private const BLANK = "\u{2800}";

    private const WINDOW_HOURS = 23;

    private const TIMEOUT = 20;

    public function __construct(
        private readonly WhatsAppCloud $cloud,
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * Deliver a report to the group with this purpose ("daily:NJ").
     *
     * @param  string  $title  what the template calls it: "A1 NJ Same-Day Report for Oct 6"
     * @param  bool  $file  false for a message that is not a report (an approval prompt): the text alone
     * @return int how many places it went: 1 for a real group, else the members reached
     */
    public function deliver(string $purpose, string $title, string $body, bool $file = true): int
    {
        $filename = Str::slug($title).'.pdf';

        return $this->fanOut($purpose, $title, $body, $file
            ? fn (WhatsAppNumber $number) => [$this->upload($number, $this->pdf($number, self::titled($title, $body)), $filename), $filename]
            : null);
    }

    /**
     * A PDF to the group: uploaded to Meta once, then sent by its media id,
     * $text, its figures as people read them, its caption (Jose, 2026-11-10).
     */
    public function deliverDocument(string $purpose, string $title, string $pdf, string $filename, ?string $text = null): int
    {
        $number = $this->numberFor($purpose);

        if ($number === null) {
            return 0;
        }

        $mediaId = $this->upload($number, $pdf, $filename);

        return $this->fanOut($purpose, $title, $text, fn () => [$mediaId, $filename]);
    }

    /**
     * PDFs to one member of the group with this purpose, from that group's
     * number: private copies (Reports, 2026-10-19). Only to a member of that
     * group. Inside the 24-hour window each goes now; outside it, ONE "report
     * ready" template asks them to write in and every PDF waits behind it.
     *
     * @param  list<array{title: string, pdf: string, filename: string}>  $documents
     * @return int how many were sent or queued; 0 when they are not a member
     */
    public function deliverDocumentsToMember(string $purpose, User $user, string $title, array $documents): int
    {
        $group = WhatsAppGroup::query()->where('purpose', $purpose)->first();
        $number = $this->numberFor($purpose);

        if ($group === null || $number === null
            || ! $group->members()->where('users.id', $user->getKey())->where('is_active', true)->exists()) {
            return 0;
        }

        return $this->deliverDocumentsToUser($number, $user, $title, $documents);
    }

    /**
     * Files to one person from this number, no group asked, each in the
     * document template under its own title. A document may name its `type`
     * (an Excel export); PDF otherwise. $title is the batch's, kept for callers.
     *
     * A document's `text` is its caption, as a report's text is (Jose, 2026-11-10).
     *
     * @param  list<array{title: string, pdf: string, filename: string, type?: string, text?: string}>  $documents
     * @return int how many were sent; 0 when the person has no usable phone
     */
    public function deliverDocumentsToUser(WhatsAppNumber $number, User $user, string $title, array $documents): int
    {
        if (($e164 = ThumbtackAssociatePhoneNumbers::e164((string) $user->phone)) === null) {
            return 0;
        }

        foreach ($documents as $d) {
            $mediaId = $this->upload($number, $d['pdf'], $d['filename'], $d['type'] ?? 'application/pdf');
            $this->toPerson($number, $user, $d['title'], $mediaId, $d['filename'], null, $d['text'] ?? null);
        }

        return count($documents);
    }

    /**
     * A report text to one person on this number (a rule's, 2026-10-20): the
     * report as a formatted PDF, its text the caption, one message (Jose,
     * 2026-11-10). False when the person has no usable phone. $key: the
     * message row's idempotency key. $issuer: the
     * Company or Organization whose logo and name head the PDF; the number's
     * organization when null.
     */
    public function deliverTextToUser(WhatsAppNumber $number, User $user, string $title, string $body, ?string $key = null, ?Model $issuer = null, ?string $pdf = null): bool
    {
        $e164 = ThumbtackAssociatePhoneNumbers::e164((string) $user->phone);

        if ($e164 === null) {
            return false;
        }

        $filename = Str::slug($title).'.pdf';
        // $pdf: a report with its own document (the weekly company PDF); else the text, printed.
        $mediaId = $this->upload($number, $pdf ?? $this->pdf($number, self::titled($title, $body), $issuer), $filename);

        return $this->toPerson($number, $user, $title, $mediaId, $filename, $key, $body);
    }

    /**
     * A message with no file (an approval prompt) as people read it on
     * WhatsApp. Inside the 24-hour window under its title, in pieces WhatsApp
     * takes; outside it in the org's text template
     * (ai_assistance.report_text_template: {{1}} the title, {{2}} the text),
     * whose values cannot hold a line break, so the lines run on and a long
     * one goes in several messages, in order.
     */
    private function textTo(WhatsAppNumber $number, string $e164, string $title, string $body): void
    {
        $to = ltrim($e164, '+');
        $org = Organization::findOrFail($number->organization_id);

        app(TenantContext::class)->run($org, function () use ($number, $e164, $to, $title, $body, $org) {
            if ($this->windowOpen($number, $to)) {
                foreach (self::pieces(self::titled($title, $body)) as $piece) {
                    $this->cloud->replyFreeForm($number, $to, ['type' => 'text', 'text' => ['body' => $piece]], $piece);
                }

                return;
            }

            $template = (string) $this->settings->get('ai_assistance.report_text_template', $org);
            $pieces = self::runOn($body);
            foreach ($pieces as $i => $piece) {
                $this->cloud->send($template, $e164, [$title.(count($pieces) > 1 ? ' ('.($i + 1).'/'.count($pieces).')' : ''), $piece],
                    from: (string) $number->display_number);
            }
        });
    }

    /**
     * The report under its title, the date on a line of its own (Jose, 2026-11-11: "line breaking
     * the date at the title"): "💵 A-1 Chimney · Revenue collected" bold, then "Mon, Nov 9".
     */
    private static function titled(string $title, string $body): string
    {
        [$name, $date] = self::titleLines($title);

        return '*'.$name.'*'.($date === null ? '' : "\n".$date).(trim($body) === '' ? '' : "\n\n".$body);
    }

    /**
     * A title's name and the date it ends with ("… · Mon, Nov 9", "… · Nov 2 - Nov 8"); null when it ends with none.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function titleLines(string $title): array
    {
        return preg_match('/^(.+) · ((?:(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), )?[A-Z][a-z]{2} \d{1,2}(?: - (?:[A-Z][a-z]{2} )?\d{1,2})?(?:, \d{4})?)$/u', $title, $m) === 1
            ? [$m[1], $m[2]] : [$title, null];
    }

    /** What a report's one message says with its PDF: the report under its title; a file with no text, its title (Jose, 2026-11-10). */
    public static function caption(string $title, ?string $body): string
    {
        return self::titled($title, self::brief((string) $body, self::CAPTION_LIMIT - mb_strlen($title) - 4));
    }

    /**
     * The report whole when it fits $max characters and $lines lines (Jose, 2026-11-10). Else what
     * says the most in the fewest lines: each section's heading, every total with the lines under it
     * (Technician sales' figure a line) and every ⚠️ warning; then its paragraphs whole, in order,
     * while they fit, and the top of the first that does not when the report is one list, a note in
     * italics never; and how many lines more the PDF has (Jose, 2026-11-11: "not even relaying any
     * information"). Too long even so, the last sections go whole, so a total is never left under
     * the wrong heading.
     */
    private static function brief(string $body, int $max, int $lines = PHP_INT_MAX): string
    {
        $all = explode("\n", $body);
        $fits = fn (array $out) => mb_strlen(implode("\n", $out)) <= $max && count($out) <= $lines;
        if ($fits($all)) {
            return $body;
        }

        // A paragraph with a total in it is the total: "💰 *TOTAL*", then "✅ 112 completed"…
        $total = [];
        $paragraph = [];
        foreach ([...$all, ''] as $i => $line) {
            if (trim($line) !== '') {
                $paragraph[] = $i;

                continue;
            }
            $has = preg_grep('/total/i', array_intersect_key($all, array_flip($paragraph))) !== [];
            $total += array_fill_keys($paragraph, $has);
            $paragraph = [];
        }
        $keep = array_map(fn (int $i) => self::opens($all[$i]) || ($total[$i] ?? false) || str_starts_with(trim($all[$i]), "\u{26A0}"), array_keys($all));
        $shown = function (array $keep) use ($all): array {
            $left = count(array_filter(array_keys($all), fn (int $i) => ! $keep[$i] && str_starts_with(trim($all[$i]), '•')));

            return [...self::kept($all, $keep), '', $left > 0 ? "_+ {$left} more in the PDF_" : '_Full detail in the PDF_'];
        };
        $sections = array_keys(array_filter($all, fn (string $line) => self::opens($line)));
        $starts = $sections;
        $end = count($all);
        while (! $fits($shown($keep)) && ($from = array_pop($starts)) !== null && in_array(true, array_slice($keep, 0, $from), true)) {
            $keep = [...array_slice($keep, 0, $from), ...array_fill(0, count($all) - $from, false)];
            $end = $from;
        }
        for ($i = $end - 1; $i >= 0 && ! $fits($shown($keep)); $i--) {
            $keep[$i] = $keep[$i] && ($total[$i] ?? false);
        }
        for ($i = 0; $i < $end; $i++) {
            $try = $keep;
            for ($from = $i; $i < $end && trim($all[$i]) !== ''; $i++) {
                $try[$i] = $try[$i] || preg_match('/^_.*_$|^[—–-]{3,}$/u', trim($all[$i])) !== 1;
            }
            if ($fits($shown($try))) {
                $keep = $try;

                continue;
            }
            // A report that is one list (Dispatcher performance, a branch's technicians) shows the top of it that fits.
            if (count($sections) <= 1) {
                for ($k = $from; $k < $i && $fits($shown(array_replace($keep, [$k => true]))); $k++) {
                    $keep[$k] = true;
                }
            }
            break;
        }
        // A section left with its heading alone says nothing: it goes too.
        foreach ($sections as $n => $at) {
            if (! in_array(true, array_slice($keep, $at + 1, ($sections[$n + 1] ?? count($all)) - $at - 1), true)) {
                $keep[$at] = false;
            }
        }

        return mb_substr(implode("\n", $shown($keep)), 0, $max);
    }

    /** A section's heading: an emoji, then its name in bold ("💵 *Revenue collected*"), and not a total. */
    private static function opens(string $line): bool
    {
        return preg_match('/^[^\s•*_]+ \*[^*]+\*$/u', trim($line)) === 1 && stripos($line, 'total') === false;
    }

    /**
     * The kept lines in order: a blank line before each section, and where the report had one,
     * but not under a section's heading, nor under a bold one ("*Connecticut — 26 jobs*" then the
     * next, a list) unless a total follows.
     *
     * @param  list<string>  $all
     * @param  list<bool>  $keep
     * @return list<string>
     */
    private static function kept(array $all, array $keep): array
    {
        $out = [];
        $gap = false;
        foreach ($all as $i => $line) {
            if (trim($line) === '') {
                $gap = true;
            } elseif ($keep[$i]) {
                $last = $out === [] ? '' : (string) end($out);
                // Under a bold heading that is not a total, only a total gets its blank line.
                $under = preg_match('/^\*[^*]+\*$/u', trim($last)) === 1 && stripos($last, 'total') === false && stripos($line, 'total') === false;
                array_push($out, ...($out !== [] && (self::opens($line) || ($gap && ! self::opens($last) && ! $under)) ? ['', $line] : [$line]));
                $gap = false;
            }
        }

        return $out;
    }

    /**
     * A report as a line template's values (report_lines_N, desk 2026-11-11): its title, its date,
     * then a value per line, since a value cannot break a line. A blank line is a blank cell; an
     * em-dash rule is a blank line. Within $slots values and the 1024 characters Meta allows the body.
     *
     * @return list<string>
     */
    public static function lineValues(string $title, ?string $body, int $slots): array
    {
        [$name, $date] = self::titleLines($title);
        $head = ['*'.$name.'*', $date ?? self::BLANK];
        $text = trim((string) preg_replace(["/^[ \t]*[—–-]{3,}[ \t]*$/mu", "/\n(?:[ \t]*\n){2,}/"], ['', "\n\n"], (string) $body));
        $lines = $text === '' ? ['The file is attached.'] : explode("\n", self::brief($text, self::LINE_ROOM - mb_strlen(implode("\n", $head)), $slots - 2));

        return array_map(fn (string $line) => ($line = trim((string) preg_replace('/\s+/u', ' ', $line))) === '' ? self::BLANK : $line, [...$head, ...$lines]);
    }

    /** A free-form message's row under the key, taken from any earlier row that had it. */
    private function keyed(WhatsAppMessage $row, ?string $key): void
    {
        if ($key !== null) {
            WhatsAppMessage::query()->where('idempotency_key', $key)->update(['idempotency_key' => null]);
            $row->forceFill(['idempotency_key' => $key])->save();
        }
    }

    /**
     * A text in pieces of at most $max, split between lines.
     *
     * @return list<string>
     */
    public static function pieces(string $body, int $max = self::TEXT_LIMIT): array
    {
        $pieces = [''];
        foreach (explode("\n", $body) as $line) {
            foreach ($line === '' ? [''] : mb_str_split($line, $max) as $part) {
                $last = array_key_last($pieces);
                $joined = $pieces[$last] === '' ? $part : $pieces[$last]."\n".$part;
                if (mb_strlen($joined) <= $max) {
                    $pieces[$last] = $joined;
                } else {
                    $pieces[] = $part;
                }
            }
        }

        return $pieces;
    }

    /**
     * A text as template values: no line breaks, tabs or runs of spaces
     * (Meta #132018), each line run on after the last, a bullet after a
     * space and anything else after " | ", in pieces of at most $max.
     *
     * @return list<string>
     */
    public static function runOn(string $body, int $max = self::VALUE_LIMIT): array
    {
        $pieces = [''];
        foreach (explode("\n", $body) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line === '' || preg_match('/^[—–-]{3,}$/u', $line)) {
                continue;
            }
            foreach (mb_str_split($line, $max) as $part) {
                $last = array_key_last($pieces);
                $glue = $pieces[$last] === '' ? '' : (str_starts_with($part, '•') ? ' ' : ' | ');
                if (mb_strlen($pieces[$last].$glue.$part) <= $max) {
                    $pieces[$last] .= $glue.$part;
                } else {
                    $pieces[] = $part;
                }
            }
        }

        return $pieces;
    }

    /** The report as a formatted page, generated now in the org's timezone. */
    private function pdf(WhatsAppNumber $number, string $body, ?Model $issuer = null): string
    {
        $org = Organization::findOrFail($number->organization_id);
        $zone = (string) app(TenantContext::class)->run($org, fn () => $this->settings->get('timezone', $org));

        return ReportPdf::render($body, $issuer ?? $org, CarbonImmutable::now($zone !== '' ? $zone : 'UTC'));
    }

    /**
     * @param  string|null  $body  the text: the file's caption, or the message when there is no file
     * @param  (callable(WhatsAppNumber): array{0: string, 1: string})|null  $file  the media id and filename, made once; null: the text alone
     */
    private function fanOut(string $purpose, string $title, ?string $body, ?callable $file): int
    {
        $group = WhatsAppGroup::query()->where('purpose', $purpose)->first();
        $number = $this->numberFor($purpose);

        if ($group === null || $number === null) {
            return 0;
        }

        if ($group->remote_id !== null) {
            [$mediaId, $filename] = $file === null ? [null, null] : $file($number);
            $this->graph('post', $number->phone_number_id.'/messages', phoneNumberId: $number->phone_number_id, body: [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'group',
                'to' => $group->remote_id,
                ...($file === null ? ['type' => 'text', 'text' => ['body' => (string) $body]]
                    : ['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename, 'caption' => self::caption($title, $body)]]),
            ]);

            return 1;
        }

        $reached = 0;
        $made = null;

        foreach ($group->members()->where('is_active', true)->get() as $user) {
            try {
                $e164 = ThumbtackAssociatePhoneNumbers::e164((string) $user->phone);
                if ($e164 === null) {
                    continue;
                }
                if ($file === null) {
                    $this->textTo($number, $e164, $title, (string) $body);
                    $reached++;

                    continue;
                }
                [$mediaId, $filename] = $made ??= $file($number);
                $reached += $this->toPerson($number, $user, $title, $mediaId, $filename, null, $body) ? 1 : 0;
            } catch (Throwable $e) {
                // One member's refusal must not cost the others their report.
                report($e);
            }
        }

        return $reached;
    }

    /**
     * Members one to one hear from the Reports number (Jose, 2026-11-09: "make
     * all the Reports use the Reports Number"); a real Meta group lives on the
     * number that made it, so it keeps that one.
     */
    private function numberFor(string $purpose): ?WhatsAppNumber
    {
        $group = WhatsAppGroup::query()->where('purpose', $purpose)->first();

        if ($group === null) {
            return null;
        }

        return ($group->remote_id === null ? WhatsAppNumber::forPurpose('reports') : null)
            ?? WhatsAppNumber::query()->where('phone_number_id', $group->phone_number_id)->first();
    }

    /** Hands a file to Meta and returns its media id. */
    public function upload(WhatsAppNumber $number, string $bytes, string $filename, string $type = 'application/pdf'): string
    {
        $response = Http::withToken(WhatsAppCloud::tokenFor((string) $number->phone_number_id))
            ->timeout(60)
            ->attach('file', $bytes, $filename, ['Content-Type' => $type])
            ->post(WhatsAppCloud::endpoint().'/'.$number->phone_number_id.'/media', [
                'messaging_product' => 'whatsapp',
                'type' => $type,
            ]);

        $id = (string) ($response->json('id') ?? '');

        if ($response->failed() || $id === '') {
            throw new RuntimeException('Meta would not take '.$filename.': '
                .($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return $id;
    }

    /**
     * Send whatever was held for this person. Called when they write in.
     */
    public function flushPending(WhatsAppNumber $number, string $counterparty): int
    {
        $held = WhatsAppPendingReport::query()
            ->where('phone_number_id', $number->phone_number_id)
            ->where('counterparty', $counterparty)
            ->whereNull('delivered_at')
            ->oldest()
            ->get();

        foreach ($held as $report) {
            $this->cloud->replyFreeForm($number, $counterparty, $report->media_id !== null
                ? ['type' => 'document', 'document' => ['id' => $report->media_id, 'filename' => $report->filename, 'caption' => $report->body]]
                : ['type' => 'text', 'text' => ['body' => $report->body]], $report->body);
            $report->forceFill(['delivered_at' => now()])->save();
        }

        return $held->count();
    }

    /**
     * Make the group on WhatsApp and keep its invite link. Needs an Official
     * Business Account; without one Meta refuses and this throws, leaving the
     * group delivering one to one.
     */
    public function create(WhatsAppGroup $group): void
    {
        $made = $this->graph('post', $group->phone_number_id.'/groups', phoneNumberId: $group->phone_number_id, body: [
            'messaging_product' => 'whatsapp',
            'subject' => mb_substr($group->subject, 0, 128),
            // Only the people we invite: a forwarded link must not let a
            // stranger read a branch's sales.
            'join_approval_mode' => 'approval_required',
        ]);

        $id = (string) ($made['id'] ?? $made['group_id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('Meta made no group for "'.$group->subject.'".');
        }

        $link = $this->graph('get', $id.'/invite_link', [], $group->phone_number_id);

        $group->forceFill(['remote_id' => $id, 'invite_link' => $link['invite_link'] ?? null])->save();
    }

    /**
     * One report to one person, ONE message: its file, $body under its title
     * the caption (Jose, 2026-11-10). Inside the 24-hour window as a document;
     * outside it in a line template (report_lines_N: the file its header, a
     * value per line) once Meta has approved them, else the org's document
     * template (ai_assistance.report_template: {{1}} the title, {{2}} the
     * report run on, since a value cannot break a line). Free text reaches only
     * someone who wrote in the last 24 hours: 93 of 107 report texts never went
     * (2026-11-04..09), and our own record of who wrote can open a window Meta
     * does not see (fake webhooks as Bar), so only a message they sent counts.
     */
    private function toPerson(WhatsAppNumber $number, User $user, string $title, string $mediaId, string $filename, ?string $key = null, ?string $body = null): bool
    {
        $e164 = ThumbtackAssociatePhoneNumbers::e164((string) $user->phone);

        if ($e164 === null) {
            return false;
        }

        $org = Organization::findOrFail($number->organization_id);
        app(TenantContext::class)->run($org, function () use ($number, $e164, $title, $mediaId, $filename, $key, $body, $org) {
            $to = ltrim($e164, '+');
            if ($this->windowOpen($number, $to)) {
                $caption = self::caption($title, $body);
                $this->keyed($this->cloud->replyFreeForm($number, $to,
                    ['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename, 'caption' => $caption]], $caption), $key);

                return;
            }
            // Its lines kept, in the smallest line template it fits (desk, 2026-11-11), once Meta has approved them.
            if (($sizes = $this->lineSizes($number)) !== []) {
                $values = self::lineValues($title, $body, max($sizes));
                $size = min(array_filter($sizes, fn (int $s) => $s >= count($values)));
                $this->cloud->send(self::LINE_TEMPLATE.$size, $e164, array_pad($values, $size, self::BLANK),
                    from: (string) $number->display_number, idempotencyKey: $key, document: ['id' => $mediaId, 'filename' => $filename]);

                return;
            }
            $this->cloud->send((string) $this->settings->get('ai_assistance.report_template', $org),
                $e164, [$title, trim((string) $body) === '' ? 'The file is attached.' : self::runOn(self::brief((string) $body, self::TEMPLATE_REPORT))[0]],
                from: (string) $number->display_number, idempotencyKey: $key,
                document: ['id' => $mediaId, 'filename' => $filename]);
        });

        return true;
    }

    /**
     * The sizes of the line templates Meta has approved on this number's account, smallest first;
     * none until it has, or when it cannot be asked, and the report goes in the run-on template.
     *
     * @return list<int>
     */
    private function lineSizes(WhatsAppNumber $number): array
    {
        $key = 'whatsapp.report_lines:'.$number->waba_id;
        if (is_array($sizes = Cache::get($key))) {
            return $sizes;
        }
        try {
            $names = array_column($this->cloud->templates((string) $number->waba_id), 'name');
        } catch (Throwable $e) {
            // Meta timed out at 9 PM on 2026-11-11 and the empty answer was kept an hour, so Technician sales
            // went as the old PDF template: a failed read keeps the last list it had and is asked again next time.
            report($e);

            return Cache::get($key.':last', []);
        }
        $sizes = array_map(fn (string $n) => (int) substr($n, strlen(self::LINE_TEMPLATE)), preg_grep('/^'.self::LINE_TEMPLATE.'\d+$/', $names) ?: []);
        sort($sizes);
        Cache::put($key, $sizes, now()->addHour());
        Cache::forever($key.':last', $sizes);

        return $sizes;
    }

    /**
     * Open only on a message Meta delivered (it carries Meta's wamid), and
     * shut again once Meta refused one of ours as a re-engagement (131047):
     * our own rows opened windows Meta did not see, and Bar got nothing
     * (2026-11-04..09). An hour short of Meta's 24, so a long report does not
     * outlive it halfway.
     */
    private function windowOpen(WhatsAppNumber $number, string $to): bool
    {
        $mine = fn () => WhatsAppMessage::query()->where('phone_number_id', $number->phone_number_id)->where('counterparty', $to);
        $last = $mine()->where('direction', WhatsAppMessage::DIRECTION_IN)->whereNotNull('wamid')
            ->where('created_at', '>', now()->subHours(self::WINDOW_HOURS))->max('created_at');

        return $last !== null && ! $mine()->where('direction', WhatsAppMessage::DIRECTION_OUT)->where('status', WhatsAppMessage::STATUS_FAILED)
            ->where('status_reason', 'like', 'Re-engagement%')->where('updated_at', '>=', $last)->exists();
    }

    /** @return array<string, mixed> */
    private function graph(string $method, string $path, array $body = [], ?string $phoneNumberId = null): array
    {
        $response = Http::withToken(WhatsAppCloud::tokenFor((string) $phoneNumberId))
            ->timeout(self::TIMEOUT)
            ->{$method}(WhatsAppCloud::endpoint().'/'.$path, $body);

        if ($response->failed()) {
            throw new RuntimeException('Meta refused '.$path.': '
                .($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return (array) $response->json();
    }
}
