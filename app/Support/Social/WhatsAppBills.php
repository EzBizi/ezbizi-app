<?php

namespace App\Support\Social;

use App\Http\Controllers\Api\FinancialBillsController;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Financials\BillReader;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A photo of a period's bills sent to the staff line (Financials, 2026-10-19):
 * read, shown back, saved on YES through Financials' own save(), which checks
 * the lines to the cent and writes the audit row. Images only: a PDF read is
 * untested. Who may: `financials.edit`, the Bills tab's own tick.
 */
final class WhatsAppBills
{
    public const PERMISSION = 'financials.edit';

    private const TTL_MINUTES = 10;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'];

    public function __construct(
        private readonly WhatsAppCloud $cloud,
        private readonly FinancialBillsController $bills,
    ) {}

    /**
     * Reads the photo and holds it for a YES. Returns the reply to send.
     */
    public function read(User $user, string $mediaId, ?string $caption): string
    {
        try {
            $file = $this->cloud->downloadMedia($mediaId);
        } catch (RuntimeException $e) {
            return "\u{274C} ".$e->getMessage();
        }

        $extension = self::EXTENSIONS[strtolower(trim(explode(';', $file['mime'])[0]))] ?? null;
        if ($extension === null) {
            return "\u{1F4F7} Only photos of bills can be read here (JPG, PNG, WEBP or HEIC). Use Financials \u{2192} Bills for anything else.";
        }

        $place = $this->place($user, (string) $caption);
        if (is_string($place)) {
            return $place;
        }

        try {
            $draft = BillReader::read($file['bytes'], $extension);
        } catch (ValidationException $e) {
            return "\u{274C} ".collect($e->errors())->flatten()->first();
        }

        $lines = collect($draft['lines'])->map(fn ($l) => "\u{2022} ".$l['name'].': '.self::money($l['cents']))->implode("\n");
        $total = $draft['total_cents'] === null ? 'no total printed' : self::money($draft['total_cents']);

        if (! $draft['adds_up']) {
            return "\u{1F9FE} Read from the photo:\n".$lines."\n\nTotal: *".$total."*\n\n\u{26A0}\u{FE0F} The lines do not add up to the total, so nothing was saved. Fix it in Financials \u{2192} Bills.";
        }

        [$from, $to] = $draft['period_from'] !== null && $draft['period_to'] !== null
            ? [$draft['period_from'], $draft['period_to']]
            : [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()];

        Cache::put(self::key($user), [
            'company_id' => $place['company']?->getKey(),
            'franchise_id' => $place['franchise']?->getKey(),
            'input' => [
                'period_from' => $from,
                'period_to' => $to,
                'lines' => $draft['lines'],
                'total_cents' => $draft['total_cents'],
                'image_token' => $this->bills->keep($file['bytes'], $extension, $user),
            ],
        ], now()->addMinutes(self::TTL_MINUTES));

        return "\u{1F9FE} *Bills for ".$place['label'].', '.$from.' to '.$to."*\n".$lines."\n\nTotal: *".$total."* \u{2705} adds up\n\nReply *YES* to save, or *NO* to cancel. _Expires in 10 minutes._";
    }

    public static function pending(User $user): bool
    {
        return Cache::has(self::key($user));
    }

    /** YES saves through Financials' save(); NO drops it. Single use either way. */
    public function answer(User $user, bool $yes): string
    {
        $held = Cache::pull(self::key($user));

        if (! $yes || $held === null) {
            return "\u{1F44D} Cancelled. Nothing was saved.";
        }

        // Re-checked at the YES: the tick may have been taken away in the ten minutes.
        if (! $user->hasPermission(self::PERMISSION)) {
            return "\u{274C} Your role cannot change bills, so nothing was saved.";
        }

        $company = $held['company_id'] ? Company::find($held['company_id']) : null;
        $franchise = $held['franchise_id'] ? Franchise::find($held['franchise_id']) : null;

        try {
            PlaceInView::assert($user, $company, $franchise, 'bill');
            $bill = $this->bills->save($user, $company, $franchise, $held['input'], 'whatsapp');
        } catch (ValidationException $e) {
            return "\u{274C} Not saved: ".collect($e->errors())->flatten()->first();
        } catch (AuthorizationException $e) {
            return "\u{274C} Not saved: ".$e->getMessage();
        }

        return "\u{2705} Saved: ".self::money($bill->total_cents).' in bills. See Financials \u{2192} Bills.';
    }

    /**
     * The place: the person's one franchise, or the one their caption names.
     *
     * @return array{company: ?Company, franchise: ?Franchise, label: string}|string the place, or a reply asking for it
     */
    public function place(User $user, string $caption): array|string
    {
        $franchises = Franchise::query()->whereIn('id', ViewScope::reachOf($user)->franchiseIds())->orderBy('name')->get();
        $named = trim($caption) === '' ? collect() : $franchises->filter(
            fn (Franchise $f) => mb_stripos($f->name, trim($caption)) !== false || mb_stripos(trim($caption), $f->name) !== false,
        );

        $pick = $named->count() === 1 ? $named->first() : ($franchises->count() === 1 ? $franchises->first() : null);

        if ($pick === null) {
            return "\u{1F4CD} Which place are these bills for? Send the photo again with the place as its caption, one of:\n"
                .$franchises->take(15)->map(fn ($f) => "\u{2022} ".$f->name)->implode("\n");
        }

        return ['company' => $pick->company, 'franchise' => $pick, 'label' => $pick->name];
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    private static function key(User $user): string
    {
        return 'wa-bill:'.$user->getKey();
    }
}
