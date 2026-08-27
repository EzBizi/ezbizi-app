<?php

namespace App\Support\Tasks;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Validation\ValidationException;

final class TaskRichText
{
    public const MAX_INPUT_BYTES = 100000;

    /** @return array{html:?string,plain:?string} */
    public function normalize(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return ['html' => null, 'plain' => null];
        }

        if (strlen($html) > self::MAX_INPUT_BYTES) {
            throw ValidationException::withMessages([
                'description' => 'The description may not exceed 100,000 bytes.',
            ]);
        }
        $cache = storage_path('app/htmlpurifier');
        if (! is_dir($cache)) {
            mkdir($cache, 0755, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cache);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', 'p,br,strong,em,ul,ol,li,a[href|target|rel]');
        $config->set('CSS.AllowedProperties', []);
        $config->set('URI.AllowedSchemes', ['https' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.Nofollow', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        $clean = trim((new HTMLPurifier($config))->purify($html));
        $clean = preg_replace_callback(
            '#<a\s+[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is',
            static fn (array $match): string => str_starts_with(strtolower(html_entity_decode($match[1])), 'https://')
                ? $match[0]
                : $match[2],
            $clean,
        ) ?? $clean;
        $plainSource = preg_replace('#<(br\s*/?|/p|/li)>#i', "\n", $clean) ?? $clean;
        $plain = html_entity_decode(strip_tags($plainSource), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[ \t]+/', ' ', $plain) ?? $plain;
        $plain = preg_replace('/\s*\R\s*/', "\n", $plain) ?? $plain;
        $plain = trim($plain);

        return [
            'html' => $clean === '' ? null : $clean,
            'plain' => $plain === '' ? null : $plain,
        ];
    }
}
