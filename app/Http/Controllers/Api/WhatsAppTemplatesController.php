<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\WhatsAppNumber;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * The numbers an automation may send from, and what each one may send.
 *
 * ── Templates hang off the NUMBER, not off the organization ──────
 *
 * This returned one flat list until 2026-09-16 and that was wrong in a way that
 * looked fine. A template is approved per WABA, and our two numbers sit on
 * DIFFERENT WABAs:
 *
 *     +1 877-853-6971  "EzBizi"          3p_direct_integration_test, hello_world
 *     +1 877-450-8572  "EzBizi Reports"  ... plus report_summary, report_document
 *
 * The flat version read `->first()` and answered with that number's WABA, so
 * the reports templates were invisible to the one screen that needed them -
 * and a rule author picking a template from the list would have had no way to
 * know it was only half of them. Same `->first()` defect as `rowFor()` had,
 * in the read rather than the write.
 *
 * So the shape is nested, and it is nested because the DOMAIN is: choose a
 * number, then choose from what that number can actually send.
 *
 * ── Why this is gated on Automation and not on a WhatsApp permission ──
 *
 * There is no WhatsApp permission, and inventing one for a read-only list
 * would be a permission nobody grants - `ModuleAvailability` runs before the
 * role, so a new grant that no role holds locks the picker for everyone
 * including the person who added it.
 *
 * ── The cache is about Meta, not about speed ─────────────────────
 *
 * Keyed per WABA, because that is what Meta rate-limits and because two
 * numbers on one WABA should not ask twice. Five minutes is short enough that
 * a newly approved template appears while somebody is still waiting for it.
 */
class WhatsAppTemplatesController extends Controller
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /**
     * GET /api/v1/automations/whatsapp-templates
     */
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Automation::class);

        // Tenant-scoped on purpose: an organization with no row gets the empty
        // answer below rather than another tenant's numbers.
        $numbers = WhatsAppNumber::query()->orderBy('display_number')->get();

        if ($numbers->isEmpty()) {
            // Not an error and deliberately not a 404. "No WhatsApp number is
            // attached here" is a true and useful answer for a builder that
            // wants to grey out the action, and a 404 would read as a broken
            // endpoint to the one screen that calls it.
            return response()->json([
                'data' => [],
                'refused' => [],
                'because' => 'No WhatsApp number is attached to this organization, so there is '
                    .'nothing to send from.',
            ]);
        }

        $data = [];
        $refused = [];

        foreach ($numbers as $number) {
            try {
                $templates = Cache::remember(
                    'whatsapp.templates.'.$number->waba_id,
                    self::CACHE_SECONDS,
                    fn () => $this->cloud->templates((string) $number->waba_id),
                );
            } catch (RuntimeException $e) {
                /*
                 * One number's WABA failing must not blank the others. Same
                 * rule as the Ads report next door: a list that quietly covers
                 * fewer things than it appears to is worse than one that says
                 * which parts it could not read.
                 */
                $refused[] = [
                    'from' => (string) $number->display_number,
                    'because' => $e->getMessage(),
                ];

                continue;
            }

            $data[] = [
                // `from` is both what to show and what to pass back as
                // `send()`'s $from. One field rather than two identical ones,
                // so there is nothing for a caller to pick wrongly.
                'from' => (string) $number->display_number,
                'label' => $number->display_name,
                'templates' => $templates,
            ];
        }

        if ($data === []) {
            /*
             * EVERY number failed, so there is nothing to show and the reason
             * is Meta rather than us. 409 rather than a 200 with an empty list:
             * an outage that renders as "no templates approved yet" sends a
             * rule author off to check Meta's review queue for a problem that
             * is not there.
             *
             * A PARTIAL failure is different and stays a 200 - the numbers that
             * answered are worth showing, with the rest named in `refused`.
             */
            return response()->json([
                'message' => $refused[0]['because'],
                'refused' => $refused,
            ], Status::HTTP_CONFLICT);
        }

        $usable = array_filter($data, fn (array $n) => $n['templates'] !== []);

        if ($usable === []) {
            /*
             * Empty needs to say WHICH empty, or a builder rendering `because`
             * verbatim shows silence for the commonest case. Nothing approved
             * yet is the normal day-one state - Meta takes hours - and it must
             * not read as "this feature is broken".
             */
            return response()->json([
                'data' => $data,
                'refused' => $refused,
                'because' => 'No WhatsApp template has been approved yet. Meta reviews each one '
                    .'before it can be sent, so this list fills in once that is done.',
            ]);
        }

        return response()->json(['data' => $data, 'refused' => $refused]);
    }
}
