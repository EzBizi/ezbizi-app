<?php

namespace App\Support\Contracts;

/**
 * A checklist answer, as the table the document prints.
 *
 * Rows are the field's options, in the option order, then any item the
 * signer added that the list did not have (A1 Tools' "Add a tool not
 * listed"). Cells we did not receive stay blank; that is an item not
 * received, not a missing row.
 *
 * HTML is generated here so MergeTags never has to trust tenant markup.
 */
final class ChecklistTable
{
    public static function html(FieldDefinition $field, ?string $json): string
    {
        $byItem = self::rows($json);

        $html = '<table class="checklist"><thead><tr>'
            .'<th>'.e($field->label).'</th>'
            .'<th>Quantity</th>'
            .'<th>Received</th>'
            .'</tr></thead><tbody>';

        $added = array_diff(array_keys($byItem), $field->options);
        foreach ([...$field->options, ...$added] as $item) {
            $row = $byItem[$item] ?? ['quantity' => '', 'received' => ''];
            $html .= '<tr><td>'.e((string) $item).'</td>'
                .'<td>'.e($row['quantity']).'</td>'
                .'<td>'.e($row['received']).'</td></tr>';
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
}
