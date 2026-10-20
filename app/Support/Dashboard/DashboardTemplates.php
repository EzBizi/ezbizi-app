<?php

namespace App\Support\Dashboard;

/**
 * Recommended dashboard layouts a person can apply in one go (Jose,
 * 2026-10-05: "also make some recommended layouts, like templates a user can
 * apply"; 2026-10-07: "it changes the layout of their dashboard that being
 * order and size of widgets and modes").
 *
 * A template is boxes in order, each with a size and, for a box big enough to
 * draw a chart, a mode (pie, bar, line, text). Applying it is an ordinary
 * PUT /dashboard/layout of the glances returned by {@see forReach()}: the ones
 * named, in order, sized and drawn as named, then every other box this person
 * can see, hidden. Hidden, not dropped, so "Add a box" can bring any of them
 * back. A Small box shows only its headline figure, so the template names no
 * mode for it, and a save without `presentation` keeps the one stored.
 *
 * Built for the reach of the person asking: a box they cannot open is not
 * named, a mode the box does not offer is not sent, and a template that would
 * show fewer than two boxes is not offered.
 */
final class DashboardTemplates
{
    /**
     * Each glance: [size] or [size, mode].
     *
     * @var array<string, array{label: string, description: string, glances: array<string, array{0: string, 1?: string}>}>
     */
    private const TEMPLATES = [
        'owner' => [
            'label' => 'Whole business',
            'description' => 'Work, sales and people at a glance: jobs large, the rest small.',
            'glances' => [
                'jobs' => ['large', 'pie'],
                'leads' => ['medium', 'line'],
                'customers' => ['medium', 'line'],
                'phone' => ['small'],
                'reviews' => ['small'],
                'work_hours' => ['small'],
                'hr' => ['small'],
                'scheduling' => ['small'],
                'marketing' => ['small'],
                'inventory' => ['small'],
                'fleet_management' => ['small'],
                'email' => ['small'],
                'automations' => ['small'],
            ],
        ],
        'office' => [
            'label' => 'Office and dispatch',
            'description' => 'Today\'s jobs, the schedule, calls and new leads.',
            'glances' => [
                'jobs' => ['large', 'bar'],
                'scheduling' => ['medium', 'bar'],
                'phone' => ['medium', 'bar'],
                'leads' => ['medium', 'line'],
                'customers' => ['small'],
                'email' => ['small'],
                'field' => ['small'],
                'work_hours' => ['small'],
            ],
        ],
        'field' => [
            'label' => 'Field operations',
            'description' => 'Who is out, the vans, and the work in front of them.',
            'glances' => [
                'field' => ['large', 'text'],
                'jobs' => ['medium', 'pie'],
                'work_hours' => ['medium', 'bar'],
                'fleet_management' => ['medium', 'pie'],
                'inspection' => ['small'],
                'inventory' => ['small'],
                'scheduling' => ['small'],
            ],
        ],
        'sales' => [
            'label' => 'Sales and marketing',
            'description' => 'Leads, posts, reviews and listings.',
            'glances' => [
                'leads' => ['large', 'line'],
                'marketing' => ['medium', 'line'],
                'reviews' => ['medium', 'bar'],
                'business_profile' => ['small'],
                'ads_performance' => ['small'],
                'web_management' => ['small'],
                'customers' => ['small'],
            ],
        ],
        'people' => [
            'label' => 'People',
            'description' => 'The team: who is on the books, on the clock and due for training.',
            'glances' => [
                'hr' => ['large', 'bar'],
                'work_hours' => ['medium', 'bar'],
                'training' => ['medium', 'text'],
                'scheduling' => ['small'],
                'field' => ['small'],
            ],
        ],
    ];

    /**
     * Every template that shows at least two of these boxes, each as a ready
     * layout: the named boxes in order, then the rest hidden.
     *
     * @param  Glance[]  $reachable  the glances this person can see
     * @return list<array{key: string, label: string, description: string, glances: list<array{key: string, size?: string, presentation?: string, hidden: bool}>}>
     */
    public static function forReach(array $reachable): array
    {
        $byKey = [];
        foreach ($reachable as $glance) {
            $byKey[$glance->key()] = $glance;
        }

        $out = [];

        foreach (self::TEMPLATES as $key => $template) {
            $glances = [];
            foreach ($template['glances'] as $glanceKey => $look) {
                if (! isset($byKey[$glanceKey])) {
                    continue;
                }
                $entry = ['key' => $glanceKey, 'size' => $look[0]];
                if (isset($look[1]) && in_array($look[1], $byKey[$glanceKey]->allowedPresentations(), true)) {
                    $entry['presentation'] = $look[1];
                }
                $glances[] = $entry + ['hidden' => false];
            }

            if (count($glances) < 2) {
                continue;
            }

            foreach (array_diff(array_keys($byKey), array_keys($template['glances'])) as $rest) {
                $glances[] = ['key' => $rest, 'hidden' => true];
            }

            $out[] = [
                'key' => $key,
                'label' => $template['label'],
                'description' => $template['description'],
                'glances' => $glances,
            ];
        }

        return $out;
    }
}
