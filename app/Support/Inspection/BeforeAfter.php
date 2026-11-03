<?php

namespace App\Support\Inspection;

use App\Models\Attachment;
use App\Models\InspectionReport;

/**
 * Before/after photo pairs, Jose 2026-11-03: the arrival inspection's photos
 * are the BEFORE; a finished-job inspection on the same job asks for the
 * AFTER. A field keyed `after_<key>` pairs with the photo field `<key>` on
 * the job's latest earlier report that answered it. A naming convention
 * rather than a builder setting, so a template author pairs by key alone.
 */
final class BeforeAfter
{
    public const PREFIX = 'after_';

    /**
     * @return list<array{label: string, before: Attachment, after: Attachment}>
     */
    public static function pairs(InspectionReport $after): array
    {
        $answers = $after->answers ?? [];
        $wanted = [];

        foreach ($after->version?->screens ?? [] as $screen) {
            foreach ($screen['fields'] ?? [] as $field) {
                $key = (string) ($field['key'] ?? '');
                if (($field['type'] ?? '') === FieldType::PHOTO && str_starts_with($key, self::PREFIX) && ! empty($answers[$key])) {
                    $wanted[substr($key, strlen(self::PREFIX))] = [(string) ($field['label'] ?? $key), (array) $answers[$key]];
                }
            }
        }

        if ($wanted === [] || $after->job_id === null) {
            return [];
        }

        // ponytail: scans the job's reports in PHP; a job has a handful.
        $earlier = InspectionReport::query()
            ->where('job_id', $after->job_id)
            ->whereKeyNot($after->getKey())
            ->where('submitted_at', '<=', $after->submitted_at ?? now())
            ->whereNull('archived_at')
            ->orderByDesc('submitted_at')
            ->with('attachments')
            ->get();

        $afterPhotos = $after->loadMissing('attachments')->attachments->keyBy('id');
        $pairs = [];

        foreach ($wanted as $beforeKey => [$label, $afterIds]) {
            $source = $earlier->first(fn (InspectionReport $r) => ! empty(($r->answers ?? [])[$beforeKey]));
            $before = $source?->attachments->firstWhere('id', (string) ((array) $source->answers[$beforeKey])[0]);
            $afterPhoto = $afterPhotos->get((string) $afterIds[0]);

            if ($before !== null && $afterPhoto !== null) {
                $pairs[] = ['label' => $label, 'before' => $before, 'after' => $afterPhoto];
            }
        }

        return $pairs;
    }
}
