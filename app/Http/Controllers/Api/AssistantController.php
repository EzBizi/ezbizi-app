<?php

namespace App\Http\Controllers\Api;

use App\Models\AssistantEntry;
use App\Support\Ai\StaffAnswer;
use App\Support\Assistant\AssistantBrain;
use App\Support\Social\WhatsAppStaffBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The assistant, inside the app - the AI tab. Jose, 2026-09-24: "so we can
 * also have the same inside the app for people who dont have whatsapp".
 *
 * The same brain as the WhatsApp line, asked about the signed-in person: they
 * work here, so they read what their reach allows, staff answers included.
 * Gated by the route on `ai_assistance.ai.view`.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantBrain $brain) {}

    /** GET /api/v1/ai-assistance/menu - everything this person may ask about, in one go. */
    public function menu(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'name' => $this->brain->name($user->organization),
            'welcome' => $this->brain->welcome($user->organization),
            'sections' => $this->brain->sections($user)->map(fn ($entries, string $section) => [
                'section' => $section,
                'entries' => $entries->map(fn (AssistantEntry $e) => $e->only(['id', 'title', 'description', 'body']))->values(),
            ])->values(),
        ]]);
    }

    /**
     * GET /api/v1/ai-assistance/ask?q= - what the WhatsApp line would find for
     * the same words: matching answers, and the places the words name.
     */
    public function ask(Request $request): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q'];
        $user = $request->user();

        return response()->json(['data' => [
            'answers' => $this->brain->search($user, $q)->take(10)
                ->map(fn (AssistantEntry $e) => $e->only(['id', 'title', 'description', 'body']))->values(),
            'locations' => $this->brain->locations($user->organization, $q)->take(20),
        ]]);
    }

    /**
     * GET /api/v1/ai-assistance/answer?q= - Grok's answer from the Library,
     * asked after /ask so the matches show at once and this follows.
     */
    public function answer(Request $request): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']])['q'];
        $user = $request->user();

        // One brain with WhatsApp and Telegram (StaffAnswer, Jose 2026-10-26):
        // parts questions to the Tech Assistant, then the Library, then the
        // app's data read as this person. 95 s, under nginx's 120.
        try {
            $found = StaffAnswer::answer($user, $user->organization, $q, 'app', 95);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'AI could not answer just now.'], 503);
        }

        $answer = match ($found['source'] ?? null) {
            null => null,
            StaffAnswer::TECH => ['reply' => $found['reply'], 'entry_ids' => [], 'tech' => true,
                'answer_id' => $found['tech']['answer']?->getKey(), 'order_check' => $found['tech']['answer']?->order_check],
            // The photos WhatsApp sends as images, as links here until the chat shows them inline.
            StaffAnswer::APP => ['reply' => implode("\n", [$found['reply'], ...($found['media'] ?? [])]), 'entry_ids' => [], 'from_app' => true, 'media' => $found['media'] ?? []],
            default => ['reply' => $found['reply'], 'entry_ids' => $found['entry_ids']],
        };

        // Jose, 2026-10-14: the app's chat reads like the WhatsApp bot - capitals, emojis for dots.
        if (is_array($answer) && isset($answer['reply']) && empty($answer['tech'])) {
            $answer['reply'] = WhatsAppStaffBot::look((string) $answer['reply']);
        }

        return response()->json(['data' => $answer]);
    }
}
