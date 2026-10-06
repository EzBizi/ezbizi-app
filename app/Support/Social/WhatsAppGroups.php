<?php

namespace App\Support\Social;

use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppPendingReport;
use Illuminate\Support\Facades\Http;
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
 * sends the report to each MEMBER one to one: as text when they wrote to the
 * line in the last 24 hours, otherwise as the approved `report_ready` template
 * with the report held until they tap "Show report" (WhatsApp allows free text
 * only inside that window). The sender never knows which happened.
 */
final class WhatsAppGroups
{
    public const READY_TEMPLATE = 'report_ready';

    private const WINDOW_HOURS = 24;

    private const TIMEOUT = 20;

    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /**
     * Deliver a report to the group with this purpose ("daily:NJ").
     *
     * @param  string  $title  what the template calls it: "A1 NJ Same-Day Report for Oct 6"
     * @return int how many places it went: 1 for a real group, else the members reached
     */
    public function deliver(string $purpose, string $title, string $body): int
    {
        $group = WhatsAppGroup::query()->where('purpose', $purpose)->first();
        $number = $group === null ? null : WhatsAppNumber::query()->where('phone_number_id', $group->phone_number_id)->first();

        if ($group === null || $number === null) {
            return 0;
        }

        if ($group->remote_id !== null) {
            $this->graph('post', $number->phone_number_id.'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'group',
                'to' => $group->remote_id,
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

            return 1;
        }

        $reached = 0;

        foreach ($group->members()->where('is_active', true)->get() as $user) {
            try {
                $reached += $this->toPerson($number, $user, $title, $body) ? 1 : 0;
            } catch (Throwable $e) {
                // One member's refusal must not cost the others their report.
                report($e);
            }
        }

        return $reached;
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
            $this->cloud->replyFreeForm($number, $counterparty, ['type' => 'text', 'text' => ['body' => $report->body]], $report->body);
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
        $made = $this->graph('post', $group->phone_number_id.'/groups', [
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

        $link = $this->graph('get', $id.'/invite_link');

        $group->forceFill(['remote_id' => $id, 'invite_link' => $link['invite_link'] ?? null])->save();
    }

    private function toPerson(WhatsAppNumber $number, User $user, string $title, string $body): bool
    {
        $e164 = ThumbtackAssociatePhoneNumbers::e164((string) $user->phone);

        if ($e164 === null) {
            return false;
        }

        $to = ltrim($e164, '+');

        if ($this->windowOpen($number, $to)) {
            $this->cloud->replyFreeForm($number, $to, ['type' => 'text', 'text' => ['body' => $body]], $body);

            return true;
        }

        WhatsAppPendingReport::create([
            'organization_id' => $number->organization_id,
            'phone_number_id' => $number->phone_number_id,
            'counterparty' => $to,
            'body' => $body,
        ]);

        $this->cloud->send(self::READY_TEMPLATE, $e164, [$title], from: (string) $number->display_number);

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
    private function graph(string $method, string $path, array $body = []): array
    {
        $response = Http::withToken((string) config('services.meta.whatsapp_token'))
            ->timeout(self::TIMEOUT)
            ->{$method}(WhatsAppCloud::endpoint().'/'.$path, $body);

        if ($response->failed()) {
            throw new RuntimeException('Meta refused '.$path.': '
                .($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return (array) $response->json();
    }
}
