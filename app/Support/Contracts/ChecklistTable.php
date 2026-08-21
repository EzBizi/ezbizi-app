<?php

namespace App\Support\Contracts;

/**
 * A checklist answer, as the table the document prints.
 *
 * Rows are the field's options, in the option order — never the JSON's. An
 * item that was not on the template is dropped rather than invented on the
 * signed page. Cells we did not receive stay blank; that is an unanswered
 * quantity, not a missing row.
 *
 * HTML is generated here so MergeTags never has to trust tenant markup.
 */
final class ChecklistTable
{
    public static function html(FieldDefinition $field, ?string $json): string
    {
        $byItem = self::rows($json);

        $html = '<table class="checklist"><thead><tr>'
            .'<th>'.self::e($field->label).'</th>'
            .'<th>Quantity</th>'
            .'<th>Received</th>'
            .'</tr></thead><tbody>';

        foreach ($field->options as $item) {
            $row = $byItem[$item] ?? ['quantity' => '', 'received' => ''];
            $html .= '<tr><td>'.self::e($item).'</td>'
                .'<td>'.self::e($row['quantity']).'</td>'
                .'<td>'.self::e($row['received']).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    public static function summary(FieldDefinition $field, ?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }

        $filled = 0;
        $byItem = self::rows($json);

        foreach ($field->options as $item) {
            $row = $byItem[$item] ?? ['quantity' => '', 'received' => ''];
            if ($row['quantity'] !== '' || $row['received'] !== '') {
                $filled++;
            }
        }

        if ($filled === 0) {
            return null;
        }

        return $filled.' of '.count($field->options).' items recorded';
    }

    /**
     * @return array<string, array{quantity: string, received: string}>
     */
    public static function rows(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $row) {
            if (! is_array($row) || ! is_string($row['item'] ?? null)) {
                continue;
            }
            $out[$row['item']] = [
                'quantity' => is_scalar($row['quantity'] ?? null) ? trim((string) $row['quantity']) : '',
                'received' => is_scalar($row['received'] ?? null) ? trim((string) $row['received']) : '',
            ];
        }

        return $out;
    }

    public static function encode(array $rows): string
    {
        return json_encode(array_values($rows), JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
