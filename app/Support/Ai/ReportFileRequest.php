<?php

namespace App\Support\Ai;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Reads a staff WhatsApp message asking for a report file ("send me the CT
 * report for last month", "the 2025 yearly in excel", "job profitability for
 * NJ last week") into what GET /reports/period takes (takeover row 50, A1Bot's
 * "PDF on request"). Grok reads it, as CallRecordingsRequest.
 */
final class ReportFileRequest
{
    /** Cheap gate before any Grok call. */
    public static function looksLikeOne(string $text): bool
    {
        return preg_match('/\b(report|reporte|informe|pdf|excel|xlsx|spreadsheet|yearly|profitability)\b/iu', $text) === 1
            && preg_match('/\b(send|give|get|need|want|share|manda|envia|env[ií]a|dame)\b/iu', $text) === 1;
    }

    /**
     * @param  list<string>  $branches  the branch names the asker can see
     * @return array{branch: ?string, from: string, to: string, format: string, report: string, clarify: ?string}|null
     *                                                                                                                 null: not a request for a report file; branch null: the whole company
     *
     * @throws RuntimeException when Grok fails
     */
    public static function read(string $text, array $branches, CarbonImmutable $today): ?array
    {
        $a = GrokCli::ask(
            'A staff member of a home-services business wrote this on WhatsApp. Decide if they are asking '
            ."to be sent a report file. Today is {$today->toDateString()} ({$today->format('l')}).\n"
            ."- is_request: true only if they want a report sent to them as a file.\n"
            .'- branch: exactly one of ['.implode(', ', array_map(fn ($b) => '"'.$b.'"', $branches)).'] '
            ."if they named one (abbreviations like CT, NJ count), or empty for the whole company.\n"
            .'- from / to: dates Y-m-d. "last week" is the last full Monday-Sunday week; "last month" the last '
            ."full month; a year is Jan 1 to Dec 31. With no dates: last week. Never later than today.\n"
            ."- format: \"xlsx\" if they ask for Excel or a spreadsheet, else \"pdf\".\n"
            ."- report: \"job_profitability\" if they ask for job profitability or profit per job, else \"period\".\n"
            ."- clarify: empty, or ONE short question if what they want is unclear.\n\n"
            .'MESSAGE: '.$text,
            [
                'type' => 'object',
                'properties' => [
                    'is_request' => ['type' => 'boolean'],
                    'branch' => ['type' => 'string'],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                    'format' => ['type' => 'string', 'enum' => ['pdf', 'xlsx']],
                    'report' => ['type' => 'string', 'enum' => ['period', 'job_profitability']],
                    'clarify' => ['type' => 'string'],
                ],
                'required' => ['is_request', 'branch', 'from', 'to', 'format', 'report', 'clarify'],
                'additionalProperties' => false,
            ],
            30,
        );

        if (($a['is_request'] ?? false) !== true) {
            return null;
        }
        $dates = fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) === 1;

        return [
            'branch' => in_array($a['branch'] ?? '', $branches, true) ? $a['branch'] : null,
            'from' => (string) $a['from'],
            'to' => (string) $a['to'],
            'format' => ($a['format'] ?? '') === 'xlsx' ? 'xlsx' : 'pdf',
            'report' => ($a['report'] ?? '') === 'job_profitability' ? 'job_profitability' : 'period',
            'clarify' => trim((string) ($a['clarify'] ?? '')) !== '' ? trim((string) $a['clarify'])
                : ($dates($a['from'] ?? '') && $dates($a['to'] ?? '') && $a['from'] <= $a['to'] ? null : 'Which dates do you want the report for?'),
        ];
    }
}
