<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Reports\ReportSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * A report as it went out by email (Jose, 2026-11-25: every report EzBizi
 * sends, with what was sent). The email was kept nowhere else; a WhatsApp
 * report is its WhatsAppMessage row, not one of these.
 */
class ReportEmail extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'report', 'recipients', 'subject', 'body', 'sent_at'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'sent_at' => 'datetime'];
    }

    /**
     * An email sent while a report was being sent (ReportSource), kept as it
     * went. Never in the way of the send: a row that cannot be written is
     * reported, and the email has gone either way.
     */
    public static function record(MessageSent $event): void
    {
        $report = ReportSource::current();
        $email = $event->message;

        if ($report === null || ! $email instanceof Email || app(TenantContext::class)->id() === null) {
            return;
        }

        try {
            self::create([
                'report' => $report,
                'recipients' => array_map(fn (Address $to) => $to->getAddress(), $email->getTo()),
                'subject' => mb_substr((string) $email->getSubject(), 0, 255),
                'body' => is_string($text = $email->getTextBody()) ? $text : trim(html_entity_decode(strip_tags((string) $email->getHtmlBody()))),
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(
            months: 24,
            column: 'sent_at',
            because: 'A report emailed to staff: to whom, when and what it said, so Reports can show '
                .'what went out. Two years, as long as a WhatsApp report is kept.',
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So a business can see which reports it emailed, to whom, and what they said.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('recipients', PersonalDataEntry::CONTACT,
                'The addresses the report went to, usually staff.', Erasure::erase()),
            new PersonalDataEntry('body', PersonalDataEntry::CONTENT,
                'The report as sent. It can name customers or staff.', Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
