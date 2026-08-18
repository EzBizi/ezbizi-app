<?php

namespace App\Support\I18n;

use App\Models\UiLocale;
use App\Models\UiString;
use App\Models\User;
use App\Support\Settings\SettingsResolver;

/**
 * UI copy as rows (§8). English is the fallback. A missing key never becomes
 * a class name.
 */
final class UiCatalogue
{
    public const FALLBACK = 'en';

    public function __construct(private readonly SettingsResolver $settings) {}

    /** @return array{locale: string, strings: array<string, string>} */
    public function strings(string $wanted): array
    {
        $english = $this->rows(self::FALLBACK);
        $code    = $this->known($wanted) ? $wanted : self::FALLBACK;

        if ($code === self::FALLBACK) {
            return ['locale' => self::FALLBACK, 'strings' => $english];
        }

        return [
            'locale'  => $code,
            'strings' => array_replace($english, $this->rows($code)),
        ];
    }

    /** @return array{locale: string, strings: array<string, string>} */
    public function forUser(User $user): array
    {
        $pref = $user->preferences['locale'] ?? null;
        $org  = (string) $this->settings->get('locale', $user->organization);

        $wanted = is_string($pref) && $pref !== '' ? $pref : $org;

        return $this->strings($wanted);
    }

    /** @return string[] */
    public function localeCodes(): array
    {
        return UiLocale::query()->orderBy('code')->pluck('code')->all();
    }

    private function known(string $code): bool
    {
        return UiLocale::query()->where('code', $code)->exists();
    }

    /** @return array<string, string> */
    private function rows(string $locale): array
    {
        return UiString::query()
            ->where('locale', $locale)
            ->pluck('text', 'key')
            ->all();
    }
}
