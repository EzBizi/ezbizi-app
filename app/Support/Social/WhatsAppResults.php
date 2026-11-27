<?php

namespace App\Support\Social;

use App\Http\Controllers\Api\FinancialBillsController;
use App\Http\Controllers\Api\FinancialResultsController;
use App\Models\FinancialResult;
use App\Models\Organization;
use App\Models\User;
use App\Support\Financials\ResultReader;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A photo of a lead source's results (ACE) sent to the staff line with
 * "results" or "ACE" in its caption (Jose, 2026-10-27, option A): read, saved
 * as pending rows in Financials → Results, and approved by a YES from
 * somebody with financial_results.approve, or later in the app. NO removes them.
 */
final class WhatsAppResults
{
    public const PERMISSION = 'financial_results.create';

    /** The caption words (ai_assistance.results_words) as one pattern. */
    public static function caption(string $organizationId): string
    {
        $org = Organization::findOrFail($organizationId);
        $words = (array) app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->get('ai_assistance.results_words', $org));

        return '/\b('.implode('|', array_map(fn ($w) => preg_quote((string) $w, '/'), $words ?: ['results'])).')\b/iu';
    }

    private const TTL_MINUTES = 10;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'];

    public function __construct(
        private readonly WhatsAppCloud $cloud,
        private readonly FinancialBillsController $bills,
        private readonly FinancialResultsController $results,
        private readonly WhatsAppBills $places,
    ) {}

    /** Reads the photo, saves pending rows, and returns the reply to send. */
    public function read(User $user, string $mediaId, ?string $caption): string
    {
        try {
            $file = $this->cloud->downloadMedia($mediaId);
        } catch (RuntimeException $e) {
            return "\u{274C} ".$e->getMessage();
        }

        $extension = self::EXTENSIONS[strtolower(trim(explode(';', $file['mime'])[0]))] ?? null;
        if ($extension === null) {
            return "\u{1F4F7} Only photos can be read here (JPG, PNG, WEBP or HEIC). Use Financials \u{2192} Results for anything else.";
        }

        // The caption names the place; the keyword that routed it here is not part of the name.
        $place = $this->places->place($user, trim((string) preg_replace(self::caption($user->organization_id), '', (string) $caption)));
        if (is_string($place)) {
            return $place;
        }

        try {
            $read = ResultReader::read($file['bytes'], $extension);
        } catch (ValidationException $e) {
            return "\u{274C} ".collect($e->errors())->flatten()->first();
        }

        // The name printed on the report, else the one this organization used last.
        $source = $read['source'] ?? FinancialResult::query()->latest()->value('source') ?? 'ACE';
        $token = $this->bills->keep($file['bytes'], $extension, $user);
        $saved = collect($read['rows'])->map(fn ($row) => $this->results->save(
            $user, $place['company'], $place['franchise'],
            ['source' => $source, 'date' => $row['date'] ?? now()->toDateString()] + $row,
            'whatsapp', $token,
        ));

        $lines = $saved->map(fn (FinancialResult $r) => "\u{2022} ".$r->date->toDateString().($r->area ? ' '.$r->area : '')
            .': '.$r->calls.' calls, '.$r->jobs.' jobs, cost '.self::money($r->cost_cents).', sales '.self::money($r->sales_cents).', profit '.self::money($r->profit_cents))->implode("\n");
        $head = "\u{1F4CA} *".$source.' results for '.$place['label']."*\n".$lines."\n\n";

        if (! $user->hasPermission('financial_results.approve')) {
            return $head."\u{23F3} Saved as pending. Somebody who approves results will check them in Financials \u{2192} Results before they count.";
        }

        Cache::put(self::key($user), $saved->pluck('id')->all(), now()->addMinutes(self::TTL_MINUTES));

        return $head."Reply *YES* to approve, or *NO* to remove them. _Until then they wait as pending in Financials \u{2192} Results._";
    }

    public static function pending(User $user): bool
    {
        return Cache::has(self::key($user));
    }

    /** YES approves the held rows; NO deletes them. Single use either way. */
    public function answer(User $user, bool $yes): string
    {
        $ids = Cache::pull(self::key($user)) ?? [];
        $rows = FinancialResult::query()->whereIn('id', $ids)->where('status', 'pending')->get()
            ->filter(fn (FinancialResult $r) => $this->results->inView($user, $r));

        if (! $yes) {
            $rows->each->delete();

            return "\u{1F44D} Removed. Nothing was saved.";
        }

        // Re-checked at the YES: the tick may have been taken away in the ten minutes.
        if (! $user->hasPermission('financial_results.approve')) {
            return "\u{23F3} Your role cannot approve results, so they wait as pending in Financials \u{2192} Results.";
        }

        $rows->each(fn (FinancialResult $r) => $this->results->approve($user, $r));

        return "\u{2705} Approved ".$rows->count().' '.($rows->count() === 1 ? 'row' : 'rows').". See Financials \u{2192} Results.";
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
    }

    private static function key(User $user): string
    {
        return 'wa-results:'.$user->getKey();
    }
}
