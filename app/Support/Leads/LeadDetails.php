<?php

namespace App\Support\Leads;

/**
 * A name, a street address and a ZIP code, read out of what the customer
 * wrote - without a model. A1 Tools shows these beside each lead, some
 * gathered by AI; Jose, 2026-09-28: "perhaps gathering address, zip and name
 * may be something we can automate without needing ai?".
 *
 * Plain patterns over the customer's own messages, newest first, so a
 * correction ("sorry, it's 215 Lakeview") wins over the first attempt. Each
 * is a best guess the screen labels as read from the conversation; nothing
 * here is written back to the lead.
 */
final class LeadDetails
{
    private const STREET = 'St|Street|Ave|Avenue|Rd|Road|Dr|Drive|Ln|Lane|Ct|Court|Blvd|Boulevard|Way|Pl|Place|Cir|Circle|Ter|Terrace|Trl|Trail|Pkwy|Parkway|Hwy|Highway|Pike|Sq|Square|Loop|Run|Row|Path|Xing|Crossing|Pt|Point';

    /**
     * @param  list<string>  $texts  the customer's messages, oldest first
     * @return array{name: ?string, address: ?string, zip: ?string}
     */
    public static function from(array $texts): array
    {
        $name = $address = $zip = null;

        foreach (array_reverse($texts) as $text) {
            // Our booking widget's "Name: / Address: / ZIP:" lines are read by their labels.
            if (preg_match_all('/^(Name|Address|ZIP):[ \t]*(\S.*?)\s*$/mi', $text, $m, PREG_SET_ORDER) >= 2) {
                $form = array_column(array_map(fn ($r) => [strtolower($r[1]), $r[2]], $m), 1, 0);
                $name ??= $form['name'] ?? null;
                $address ??= $form['address'] ?? null;
                $zip ??= preg_match('/^\d{5}$/', $form['zip'] ?? '') ? $form['zip'] : null;
            }
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

            $address ??= self::address($text);
            $zip ??= self::zip($text);
            $name ??= self::name($text);
        }

        return ['name' => $name, 'address' => $address, 'zip' => $zip];
    }

    /**
     * The phone and email the customer wrote, newest first: how a Messenger
     * or Instagram lead, which arrives with neither, is told apart as an
     * existing customer (Jose, 2026-11-06).
     *
     * @param  list<string>  $texts  the customer's messages, oldest first
     * @return array{phone: ?string, email: ?string} phone as digits, email lower-cased
     */
    public static function contacts(array $texts): array
    {
        $phone = $email = null;

        foreach (array_reverse($texts) as $text) {
            if ($email === null && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
                $email = strtolower($m[0]);
            }
            if ($phone === null && preg_match('/(?<!\d)(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/', $text, $m)) {
                $phone = preg_replace('/\D/', '', $m[0]);
            }
        }

        return ['phone' => $phone, 'email' => $email];
    }

    /** "215 Lakeview Dr Michigan City Indiana" -> "215 Lakeview Dr Michigan City Indiana". */
    public static function address(string $text): ?string
    {
        $street = '/\b(\d{1,6}\s+(?:[NSEW]\.?\s+)?(?:[A-Za-z0-9\'.-]+\s+){0,4}(?:'.self::STREET.')\b\.?'
            // The rest of the line up to a sentence end: city, state, ZIP.
            .'(?:[ ,]+[A-Za-z][A-Za-z .\'-]*?)?(?:,?\s*\b[A-Z]{2}\b)?(?:\s*\d{5}(?:-\d{4})?)?)(?=[.!?]|\s{2}|$|,?\s+(?:in|on|near|by|and|but|i|i\'m|i am|we|we\'re|available|friday|saturday|monday|tuesday|wednesday|thursday|sunday)\b)/i';

        return preg_match($street, $text, $m) ? rtrim(trim($m[1]), ',.') : null;
    }

    /** The last five-digit number that is not part of a phone number or a street number at the start. */
    public static function zip(string $text): ?string
    {
        preg_match_all('/(?<![\d-])(\d{5})(?:-\d{4})?(?![\d-])/', $text, $m, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($m[1] ?? []) as [$digits, $at]) {
            // "12345 Main St" - a house number, not a ZIP.
            if (preg_match('/^\s+(?:[NSEW]\.?\s+)?[A-Za-z]/', substr($text, $at + 5)) && ! preg_match('/\b[A-Z]{2}\s*$/', substr($text, 0, $at))) {
                continue;
            }

            return $digits;
        }

        return null;
    }

    /** "my name is Jacob Garcia" / "this is Dave Malkowski" / "Dave Malkowski 2196692720". */
    public static function name(string $text): ?string
    {
        $word = "[A-Z][a-z'-]+";
        if (preg_match("/\b(?:my name is|name is|this is|it's|i am|i'm)\s+({$word}(?:\s+{$word})?)/i", $text, $m)
            && ! preg_match('/^(?:Interested|Looking|Wondering|Calling|Writing|Here|Available|Not|Just|The|A|An)$/i', explode(' ', $m[1])[0])) {
            return ucwords($m[1]);
        }
        // A message that is only a name and a phone number.
        if (preg_match("/^({$word}\s+{$word})[\s,.-]*\+?[\d\s().-]{10,}$/", $text, $m)) {
            return $m[1];
        }

        return null;
    }
}
