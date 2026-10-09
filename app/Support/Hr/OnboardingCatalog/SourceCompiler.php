<?php

namespace App\Support\Hr\OnboardingCatalog;

use RuntimeException;

/**
 * Section markers in a catalog source, never merge tags.
 *
 * A-1 Tools kept both sides of two either/or decisions in one file and deleted
 * clauses at render time (`hourly_only`, `raise_only`).
 *
 * Markers are `<<hourly>>…<</hourly>>`, `<<salary>>…<</salary>>`,
 * `<<raise>>…<</raise>>`. They may nest (a raise clause that says "per hour"
 * only on an hourly hire). They must never appear in a stored version.
 */
final class SourceCompiler
{
    public const HOURLY = 'hourly';
    public const SALARY = 'salary';
    public const RAISE  = 'raise';

    /**
     * Turn compile-time markers into live merge sections.
     *
     * The catalog used to split hourly/salary/raise into separate templates
     * because EzBizi could not drop a clause. It can now. These markers never
     * reach a stored version — they become `{{#pay_basis.hourly}}` and
     * `{{#raise_amount}}`, which MergeTags keeps or deletes from the answers.
     */
    public static function toLiveSections(string $source): string
    {
        $map = [
            self::HOURLY => 'pay_basis.hourly',
            self::SALARY => 'pay_basis.salary',
            self::RAISE  => 'raise_amount',
        ];

        $pattern = '/<<([a-z]+)>>((?:(?!<<[a-z]+>>|<<\/[a-z]+>>).)*)<<\/\1>>/s';
        $guard   = 0;

        while (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) === 1) {
            if (++$guard > 1000) {
                throw new RuntimeException(
                    'Onboarding catalog source did not finish compiling. A section marker is probably unclosed.',
                );
            }

            $flag = $match[1][0];

            if (! isset($map[$flag])) {
                throw new RuntimeException(sprintf(
                    "Onboarding catalog source has a section '%s' this compiler does not keep or drop.",
                    $flag,
                ));
            }

            $key         = $map[$flag];
            $replacement = '{{#'.$key.'}}'.$match[2][0].'{{/'.$key.'}}';
            $source      = substr_replace($source, $replacement, $match[0][1], strlen($match[0][0]));
        }

        if (str_contains($source, '<<')) {
            throw new RuntimeException(
                'Onboarding catalog source still has section markers after compile.',
            );
        }

        $source = str_replace('{{employer_address}}', '{{organization.address}}', $source);
        $source = preg_replace("/\n{3,}/", "\n\n", $source) ?? $source;

        return trim($source)."\n";
    }
}
