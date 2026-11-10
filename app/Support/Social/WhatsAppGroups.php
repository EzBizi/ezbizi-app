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
 * sends the report to each MEMBER one to one: the text as they read it
 * (textTo), then the same report as a formatted PDF in the approved document
 * template (toPerson), whether or not they wrote in the last 24 hours. The
 * sender never knows which happened.
 */
final class WhatsAppGroups
{
    /** A free-form text's pieces: WhatsApp refuses a text over 4096 characters. */
    private const TEXT_LIMIT = 4000;

    /** A template value's pieces: the whole body may be 1024, the template's own words included. */
    private const VALUE_LIMIT = 800;

    private const WINDOW_HOURS = 24;

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

        return $this->fanOut($purpose, $title, ['type' => 'text', 'text' => ['body' => $body]], $file
            ? fn (WhatsAppNumber $number) => [$this->upload($number, $this->pdf($number, $body), $filename), $filename]
            : null);
    }

    /**
     * A PDF to the group: uploaded to Meta once, then sent by its media id.
     */
    public function deliverDocument(string $purpose, string $title, string $pdf, string $filename): int
    {
        $number = $this->numberFor($purpose);

        if ($number === null) {
            return 0;
        }

        $mediaId = $this->upload($number, $pdf, $filename);

        return $this->fanOut($purpose, $title, [
            'type' => 'document',
            'document' => ['id' => $mediaId, 'filename' => $filename, 'caption' => "\u{1F4C4} *".$title.'*'],
        ], fn () => [$mediaId, $filename]);
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
     * @param  list<array{title: string, pdf: string, filename: string, type?: string}>  $documents
     * @return int how many were sent; 0 when the person has no usable phone
     */
    public function deliverDocumentsToUser(WhatsAppNumber $number, User $user, string $title, array $documents): int
    {
        if (ThumbtackAssociatePhoneNumbers::e164((string) $user->phone) === null) {
            return 0;
        }

        foreach ($documents as $d) {
            $this->toPerson($number, $user, $d['title'], $this->upload($number, $d['pdf'], $d['filename'], $d['type'] ?? 'application/pdf'), $d['filename']);
        }

        return count($documents);
    }

    /**
     * A report text to one person on this number (a rule's, 2026-10-20): the
     * text, then the same report as a formatted PDF in the document template
     * (Jose, 2026-11-09: "I want both. The message that shows the data and the
     * attachment."). False when the person has no usable phone. $key: the
     * message rows' idempotency key, the text's pieces under it. $issuer: the
     * Company or Organization whose logo and name head the PDF; the number's
     * organization when null.
     */
    public function deliverTextToUser(WhatsAppNumber $number, User $user, string $title, string $body, ?string $key = null, ?Model $issuer = null): bool
    {
        $e164 = ThumbtackAssociatePhoneNumbers::e164((string) $user->phone);

        if ($e164 === null) {
            return false;
        }

        $filename = Str::slug($title).'.pdf';
        $mediaId = $this->upload($number, $this->pdf($number, $body, $issuer), $filename);
        $this->textTo($number, $e164, $title, $body, $key);

        return $this->toPerson($number, $user, $title, $mediaId, $filename, $key);
    }

    /**
     * The report's text as people read it on WhatsApp. Inside the 24-hour
     * window as written, in pieces WhatsApp takes; outside it in the org's
     * text template (ai_assistance.report_text_template: {{1}} the report and
     * its date, {{2}} the text), whose values cannot hold a line break, so the
     * lines run on and a long report goes in several messages, in order.
     */
    private function textTo(WhatsAppNumber $number, string $e164, string $title, string $body, ?string $key): void
    {
        $to = ltrim($e164, '+');
        $org = Organization::findOrFail($number->organization_id);

        app(TenantContext::class)->run($org, function () use ($number, $e164, $to, $title, $body, $key, $org) {
            if ($this->windowOpen($number, $to)) {
                foreach (self::pieces($body) as $i => $piece) {
                    $row = $this->cloud->replyFreeForm($number, $to, ['type' => 'text', 'text' => ['body' => $piece]], $piece);
                    if ($key !== null) {
                        WhatsAppMessage::query()->where('idempotency_key', $key.':text:'.($i + 1))->update(['idempotency_key' => null]);
                        $row->forceFill(['idempotency_key' => $key.':text:'.($i + 1)])->save();
                    }
                }

                return;
            }

            $template = (string) $this->settings->get('ai_assistance.report_text_template', $org);
            $pieces = self::runOn($body);
            foreach ($pieces as $i => $piece) {
                $this->cloud->send($template, $e164, [$title.(count($pieces) > 1 ? ' ('.($i + 1).'/'.count($pieces).')' : ''), $piece],
                    from: (string) $number->display_number, idempotencyKey: $key === null ? null : $key.':text:'.($i + 1));
            }
        });
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

    /** The text as a page: WhatsApp's *bold* and _italic_ kept, emoji the PDF font has no glyph for dropped. */
    public static function reportHtml(string $body): string
    {
        $text = e(preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $body) ?? $body);
        $text = preg_replace(['/\*([^*\n]+)\*/', '/(?<!\w)_([^_\n]+)_(?!\w)/'], ['<b>$1</b>', '<i>$1</i>'], $text) ?? $text;

        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#111}</style>'
            .'</head><body>'.nl2br(trim($text)).'</body></html>';
    }

    /**
     * @param  array<string, mixed>  $message  the Cloud API message to a real group, minus who it is to
     * @param  (callable(WhatsAppNumber): array{0: string, 1: string})|null  $file  the media id and filename members get, made once; null: a text alone
     */
    private function fanOut(string $purpose, string $title, array $message, ?callable $file): int
    {
        $group = WhatsAppGroup::query()->where('purpose', $purpose)->first();
        $number = $this->numberFor($purpose);

        if ($group === null || $number === null) {
            return 0;
        }

        if ($group->remote_id !== null) {
            $this->graph('post', $number->phone_number_id.'/messages', phoneNumberId: $number->phone_number_id, body: [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'group',
                'to' => $group->remote_id,
                ...$message,
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
                // The text first, as they read it; then the report's file.
                if ($message['type'] === 'text') {
                    $this->textTo($number, $e164, $title, $message['text']['body'], null);
                }
                if ($file === null) {
                    $reached++;

                    continue;
                }
                [$mediaId, $filename] = $made ??= $file($number);
                $reached += $this->toPerson($number, $user, $title, $mediaId, $filename) ? 1 : 0;
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
     * One report to one person: the uploaded file in the org's approved
     * document template (ai_assistance.report_template: {{1}} the report),
     * every time. Free text reaches only someone who
     * wrote in the last 24 hours: held behind a "reply to get it" notice, 93
     * of 107 report texts never went (2026-11-04..09), and our own record of
     * who wrote can open a window Meta does not see (fake webhooks as Bar).
     */
    private function toPerson(WhatsAppNumber $number, User $user, string $title, string $mediaId, string $filename, ?string $key = null): bool
    {
        $e164 = ThumbtackAssociatePhoneNumbers::e164((string) $user->phone);

        if ($e164 === null) {
            return false;
        }

        $org = Organization::findOrFail($number->organization_id);
        app(TenantContext::class)->run($org, fn () => $this->cloud->send((string) $this->settings->get('ai_assistance.report_template', $org),
            $e164, [$title], from: (string) $number->display_number, idempotencyKey: $key,
            document: ['id' => $mediaId, 'filename' => $filename]));

        return true;
    }

    private function windowOpen(WhatsAppNumber $number, string $to): bool
    {
        return WhatsAppMessage::query()
            ->where('phone_number_id', $number->phone_number_id)
            ->where('counterparty', $to)
            ->where('direction', WhatsAppMessage::DIRECTION_IN)
            ->where('created_at', '>', now()->subHours(self::WINDOW_HOURS))
            ->exists();
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
