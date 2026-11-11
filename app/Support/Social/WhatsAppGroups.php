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

    /** The report under its title: the company, the report and its date (Jose, 2026-11-10). */
    private static function titled(string $title, string $body): string
    {
        return '*'.$title."*\n\n".$body;
    }

    /** What a report's one message says with its PDF: the report under its title; a file with no text, its title (Jose, 2026-11-10). */
    public static function caption(string $title, ?string $body): string
    {
        return trim((string) $body) === '' ? '*'.$title.'*'
            : self::titled($title, self::brief((string) $body, self::CAPTION_LIMIT - mb_strlen($title) - 4));
    }

    /** The report whole when it fits $max; else its headings and totals and "Full detail in the PDF" (Jose, 2026-11-10). */
    private static function brief(string $body, int $max): string
    {
        if (mb_strlen($body) <= $max) {
            return $body;
        }

        $more = "\n\n_Full detail in the PDF_";
        // A heading is an emoji then bold words ("💵 *Revenue collected*"); a total says so; a ⚠️ warning is kept too.
        $lines = array_values(array_filter(explode("\n", $body), fn (string $line) => preg_match('/^[^\s•*_]+ \*[^*]+\*$|total|^\x{26A0}/iu', trim($line)) === 1));
        while ($lines !== [] && mb_strlen(implode("\n", $lines).$more) > $max) {
            array_pop($lines);
        }

        return mb_substr(implode("\n", $lines), 0, max(0, $max - mb_strlen($more))).$more;
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
     * outside it in the org's approved document template
     * (ai_assistance.report_template: the file its header, {{1}} the title,
     * {{2}} the report run on, since a value cannot break a line). Free text reaches only
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
            $this->cloud->send((string) $this->settings->get('ai_assistance.report_template', $org),
                $e164, [$title, trim((string) $body) === '' ? 'The file is attached.' : self::runOn(self::brief((string) $body, self::TEMPLATE_REPORT))[0]],
                from: (string) $number->display_number, idempotencyKey: $key,
                document: ['id' => $mediaId, 'filename' => $filename]);
        });

        return true;
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
