<?php

namespace App\Support\Reports;

use RuntimeException;
use ZipArchive;

/**
 * The smallest .xlsx Excel opens: one sheet per title, numbers as numbers,
 * everything else as inline text. ponytail: no styles, widths or formulas;
 * add a library when an export needs them.
 */
final class Xlsx
{
    /** @param array<string, list<list<mixed>>> $sheets title => rows */
    public static function write(array $sheets): string
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not write the workbook.');
        }
        $titles = array_keys($sheets);
        $n = range(1, count($titles));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', $n))
            .'</Types>');
        $zip->addFromString('_rels/.rels', $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .implode('', array_map(fn ($i) => '<sheet name="'.self::esc(mb_substr($titles[$i - 1], 0, 31)).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>', $n))
            .'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', array_map(fn ($i) => '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>', $n))
            .'</Relationships>');
        foreach ($n as $i) {
            $rows = '';
            foreach (array_values($sheets[$titles[$i - 1]]) as $r => $row) {
                $rows .= '<row r="'.($r + 1).'">';
                foreach (array_values($row) as $c => $v) {
                    $ref = self::column($c).($r + 1);
                    $rows .= is_int($v) || is_float($v)
                        ? '<c r="'.$ref.'"><v>'.$v.'</v></c>'
                        : '<c r="'.$ref.'" t="inlineStr"><is><t>'.self::esc((string) $v).'</t></is></c>';
                }
                $rows .= '</row>';
            }
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        }
        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }

    /**
     * A sheet back as rows of strings and numbers, for tests and checks.
     *
     * @return list<list<string|float>>
     */
    public static function read(string $bytes, string $title): array
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $bytes);
        $zip = new ZipArchive;
        $zip->open($file);
        $book = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $i = 0;
        foreach ($book->sheets->sheet as $k => $s) {
            $i++;
            if ((string) $s['name'] === $title) {
                break;
            }
        }
        $sheet = simplexml_load_string((string) $zip->getFromName("xl/worksheets/sheet{$i}.xml"));
        $zip->close();
        unlink($file);
        $out = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $cells[] = (string) $c['t'] === 'inlineStr' ? (string) $c->is->t : (float) $c->v;
            }
            $out[] = $cells;
        }

        return $out;
    }

    private static function column(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
