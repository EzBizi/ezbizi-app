<?php

namespace App\Support\Social;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Notifications\LeadNotificationText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A Facebook (or Instagram) lead-ad form, submitted, becomes a "Meta Form" lead.
 *
 * Meta's `leadgen` webhook carries only an id; the answers are read back with
 * the Page's token (`leads_retrieval`). Jose, 2026-10-06: "Yes, build it".
 */
final class MetaLeadForms
{
    private const TIMEOUT = 20;

    /** The standard questions Meta's form builder offers, by their field name. */
    private const NAME = ['full_name'];

    private const PHONE = ['phone_number', 'phone'];

    private const EMAIL = ['email'];

    private const ZIP = ['zip_code', 'post_code', 'postal_code'];

    public function __construct(private readonly EventEmitter $events) {}

    /**
     * One `leadgen` change. True when it made a lead.
     *
     * @param  array<string, mixed>  $value
     */
    public function absorb(array $value, SocialConnection $page): bool
    {
        $leadgenId = (string) ($value['leadgen_id'] ?? '');

        if ($leadgenId === '') {
            return false;
        }

        // Keyed apart from a DM lead, whose remote_id is a Messenger sender id.
        $remoteId = 'form:'.$leadgenId;

        // Meta retries a delivery it did not get a 200 for.
        if (Lead::query()->where('remote_id', $remoteId)->exists()) {
            return false;
        }

        $answers = $this->read($leadgenId, $page);
        $pick = fn (array $names) => collect($names)->map(fn ($n) => $answers[$n] ?? null)->first(fn ($v) => $v !== null && $v !== '');

        $phone = $pick(self::PHONE);
        $zip = $pick(self::ZIP);
        $name = $pick(self::NAME) ?? trim(($answers['first_name'] ?? '').' '.($answers['last_name'] ?? ''));

        // Every answer that is not one of the contact fields, as "Question: answer".
        $known = [...self::NAME, ...self::PHONE, ...self::EMAIL, ...self::ZIP, 'first_name', 'last_name', '@platform', '@created_time'];
        $rest = collect($answers)->except($known)
            ->map(fn ($answer, $question) => str_replace('_', ' ', ucfirst((string) $question)).': '.$answer)
            ->implode('; ');

        /*
         * The chat came first (A-1, 2026-10-29): the Messenger lead this form
         * opened already exists and its first message carries the phone. The
         * answers go on that lead instead of a second one.
         */
        $digits = $phone !== null && strlen((string) Lead::digits($phone)) >= 10 ? substr((string) Lead::digits($phone), -10) : null;
        $chat = $digits === null ? null : Lead::query()
            ->where('source', ($answers['@platform'] ?? null) === 'ig' ? Lead::SOURCE_INSTAGRAM : Lead::SOURCE_FACEBOOK)
            ->where('lead_type', LeadNotificationText::META_TEXT)
            ->where('created_at', '>=', now()->subDay())
            ->whereHas('conversations', fn ($q) => $q->where('text', 'like', '%Phone number:%'))
            ->latest()->get()
            ->first(fn (Lead $l) => $l->conversations()->where('text', 'like', '%Phone number:%')->get()
                ->contains(fn ($c) => MetaMessages::formPhone(['message' => ['text' => $c->text]]) === $digits));

        if ($chat !== null) {
            $chat->forceFill(array_filter([
                'phone' => $chat->phone ?? $phone,
                'phone_digits' => $chat->phone_digits ?? Lead::digits($phone),
                'email' => $chat->email ?? $pick(self::EMAIL),
                'postal_code' => $chat->postal_code ?? ($zip !== null && preg_match('/^\d{5}(?:-\d{4})?$/', $zip) === 1 ? $zip : null),
            ], fn ($v) => $v !== null))->save();

            return true;
        }

        $lead = Lead::query()->create([
            'organization_id' => $page->organization_id,
            'company_id' => $page->scope_type === SocialConnection::SCOPE_COMPANY ? $page->scope_id : null,
            'franchise_id' => $page->leads_franchise_id
                ?? ($page->scope_type === SocialConnection::SCOPE_FRANCHISE ? $page->scope_id : null),
            'connection_id' => $page->getKey(),
            // An Instagram lead ad is delivered through the Page too.
            'source' => ($answers['@platform'] ?? null) === 'ig' ? Lead::SOURCE_INSTAGRAM : Lead::SOURCE_FACEBOOK,
            'lead_type' => LeadNotificationText::META_FORM,
            'remote_id' => $remoteId,
            'contact_name' => $name !== '' ? $name : null,
            'phone' => $phone,
            'phone_digits' => $phone !== null ? Lead::digits($phone) : null,
            'email' => $pick(self::EMAIL),
            'postal_code' => $zip !== null && preg_match('/^\d{5}(?:-\d{4})?$/', $zip) === 1 ? $zip : null,
            'summary' => LeadNotificationText::clip($rest !== '' ? $rest : $page->display_name, 255),
            'status' => Lead::STATUS_NEW,
            'received_at' => isset($answers['@created_time']) ? Carbon::parse($answers['@created_time']) : now(),
        ]);

        $this->events->emitAfterCommit('lead.received', $lead, [
            'after' => $lead->only(['source', 'contact_name', 'phone', 'category', 'received_at']) + [
                'kind' => $lead->kind(),
            ],
        ]);

        return true;
    }

    /**
     * The form's answers by field name, plus `@platform` and `@created_time`.
     *
     * @return array<string, string>
     */
    private function read(string $leadgenId, SocialConnection $page): array
    {
        $response = Http::timeout(self::TIMEOUT)->get(
            'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/'.$leadgenId,
            [
                'fields' => 'created_time,field_data,platform',
                'access_token' => $page->credentials['access_token'] ?? '',
            ],
        );

        if ($response->failed()) {
            throw new RuntimeException('Meta would not hand over lead '.$leadgenId.': '
                .($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        $answers = [];

        foreach ((array) $response->json('field_data', []) as $field) {
            $answers[(string) ($field['name'] ?? '')] = implode(', ', (array) ($field['values'] ?? []));
        }

        unset($answers['']);

        return $answers + array_filter([
            '@platform' => $response->json('platform'),
            '@created_time' => $response->json('created_time'),
        ]);
    }
}
