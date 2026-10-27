<?php

namespace App\Support\Ai;

use App\Models\Organization;
use App\Models\User;
use App\Support\Assistant\AssistantBrain;
use App\Support\Assistant\TechAssistant;

/**
 * ONE brain for a staff question, whatever it came through: the AI tab,
 * WhatsApp or Telegram (Jose, 2026-10-26: the same question got three
 * different answers - AppAsk's, the Reports bot's and the Tech Assistant's).
 *
 * In order, each a step only when the one before had nothing:
 *   1. a clear parts or manuals question (a part, a plate, "which pilot...")
 *      goes to the Tech Assistant, and its parts template stays with it;
 *   2. the Library (AssistantBrain::aiAnswer), the company's own answers;
 *   3. the app's data, read as this person with their permissions (AppAsk).
 * A data question ("how many jobs tomorrow") is never a parts question, even
 * when it names the company: "a-1 chimney" is not a fireplace part.
 */
final class StaffAnswer
{
    public const TECH = 'tech';

    public const LIBRARY = 'library';

    public const APP = 'app';

    /** A parts or manuals question, and not a question about the business's own data. */
    public static function isPartsQuestion(string $text): bool
    {
        // "new job" starts a Tech Assistant job: a command to it, not a data question.
        return TechAssistant::isNewJob($text) || (TechAssistant::looksTechnical($text) && ! self::asksData($text));
    }

    /** How many / which jobs, leads, calls, customers, money or dates: the app's data. */
    public static function asksData(string $text): bool
    {
        return preg_match('/\b(how many|how much|cu[aá]nt[oa]s?|jobs?|trabajos?|leads?|calls?|llamadas|customers?|clientes?|schedul\w*|booked|appointments?|tomorrow|ma[nñ]ana|yesterday|ayer|today|hoy|this week|last week|next week|revenue|sales|ventas|invoices?|estimates?|payments?|reviews?|technicians?|dispatchers?|reports?)\b/iu', $text) === 1;
    }

    /**
     * @param  array<string, string>  $files  a photo, for the Tech Assistant only
     * @return array{source: string, reply: string, entry_ids: list<string>, tech: ?array}|null
     *
     * @throws \RuntimeException when every step that ran failed rather than found nothing
     */
    public static function answer(User $user, Organization $organization, string $question, string $channel, int $budget, array $files = []): ?array
    {
        // No AI on this install: nothing to answer with, which is not a failure.
        if (! GrokCli::configured()) {
            return null;
        }

        $started = microtime(true);
        $left = fn () => $budget - (int) ceil(microtime(true) - $started);
        $failed = null;

        $tech = app(TechAssistant::class);
        if (GrokCli::configured() && $tech->enabled($organization) && ($files !== [] || self::isPartsQuestion($question))) {
            try {
                $result = $tech->answer($user, $organization, $question, $channel, $files, min($files === [] ? 60 : 120, max(10, $left() - 5)));
                if ($result !== null) {
                    return ['source' => self::TECH, 'reply' => (string) $result['reply'], 'entry_ids' => [], 'tech' => $result];
                }
            } catch (\RuntimeException $e) {
                $failed = $e;
            }
            if ($files !== []) {
                return null;   // a photo is the Tech Assistant's alone
            }
        }

        try {
            $library = $left() >= 10 ? app(AssistantBrain::class)->aiAnswer($user, $organization, $question, min(35, $left()), forWhatsApp: true) : null;
            if ($library !== null) {
                return ['source' => self::LIBRARY, 'reply' => (string) $library['reply'], 'entry_ids' => $library['entry_ids'] ?? [], 'tech' => null];
            }
        } catch (\RuntimeException $e) {
            $failed = $e;
        }

        try {
            $reply = $left() >= 20 ? AppAsk::answer($user, $question, $left() - 5) : null;
            if ($reply !== null) {
                return ['source' => self::APP, 'reply' => $reply, 'entry_ids' => [], 'tech' => null];
            }
        } catch (\RuntimeException $e) {
            $failed = $e;
        }

        if ($failed !== null) {
            throw $failed;
        }

        return null;
    }
}
