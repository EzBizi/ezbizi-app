<?php

namespace App\Support\Dashboard;

/**
 * Recommended dashboard layouts a person can apply in one go (Jose,
 * 2026-10-05: "also make some recommended layouts, like templates a user can
 * apply").
 *
 * A template is boxes in order with a size each. Applying it is an ordinary
 * PUT /dashboard/layout of the glances returned by {@see forReach()}: the ones
 * named, in order and sized, then every other box this person can see, hidden.
 * Hidden, not dropped, so "Add a box" can bring any of them back. Chart types
 * are left out on purpose; a save without `presentation` keeps the stored one,
 * so applying a template never resets somebody's charts.
 *
 * Built for the reach of the person asking: a box they cannot open is not
 * named, and a template that would show fewer than two boxes is not offered.
 */
final class DashboardTemplates
{
    /** @var array<string, array{label: string, description: string, glances: array<string, string>}> */
    private const TEMPLATES = [
        'owner' => [
            'label' => 'Whole business',
            'description' => 'Work, sales and people at a glance: jobs large, the rest small.',
            'glances' => [
                'jobs' => 'large',
                'leads' => 'medium',
                'customers' => 'medium',
                'phone' => 'small',
                'reviews' => 'small',
                'work_hours' => 'small',
                'hr' => 'small',
                'scheduling' => 'small',
                'marketing' => 'small',
                'inventory' => 'small',
                'fleet_management' => 'small',
                'email' => 'small',
                'automations' => 'small',
            ],
        ],
        'office' => [
            'label' => 'Office and dispatch',
            'description' => 'Today\'s jobs, the schedule, calls and new leads.',
            'glances' => [
                'jobs' => 'large',
                'scheduling' => 'medium',
                'phone' => 'medium',
                'leads' => 'medium',
                'customers' => 'small',
                'email' => 'small',
                'field' => 'small',
                'work_hours' => 'small',
            ],
        ],
        'field' => [
            'label' => 'Field operations',
            'description' => 'Who is out, the vans, and the work in front of them.',
            'glances' => [
                'field' => 'large',
                'jobs' => 'medium',
                'work_hours' => 'medium',
                'fleet_management' => 'medium',
                'inspection' => 'small',
                'inventory' => 'small',
                'scheduling' => 'small',
            ],
        ],
        'sales' => [
            'label' => 'Sales and marketing',
            'description' => 'Leads, posts, reviews and listings.',
            'glances' => [
                'leads' => 'large',
                'marketing' => 'medium',
                'reviews' => 'medium',
                'a1leads_gbp' => 'small',
                'business_profile' => 'small',
                'ads_performance' => 'small',
                'web_management' => 'small',
                'customers' => 'small',
            ],
        ],
        'people' => [
            'label' => 'People',
            'description' => 'The team: who is on the books, on the clock and due for training.',
            'glances' => [
                'hr' => 'large',
                'work_hours' => 'medium',
                'training' => 'medium',
                'scheduling' => 'small',
                'field' => 'small',
            ],
        ],
    ];

    /**
     * Every template that shows at least two of these boxes, each as a ready
     * layout: the named boxes in order, then the rest hidden.
     *
     * @param  list<string>  $reachable  glance keys this person can see
     * @return list<array{key: string, label: string, description: string, glances: list<array{key: string, size?: string, hidden: bool}>}>
     */
    public static function forReach(array $reachable): array
    {
        $out = [];

        foreach (self::TEMPLATES as $key => $template) {
            $shown = array_values(array_filter(
                array_keys($template['glances']),
                fn (string $glance) => in_array($glance, $reachable, true),
            ));

            if (count($shown) < 2) {
                continue;
            }

            $glances = array_map(
                fn (string $glance) => ['key' => $glance, 'size' => $template['glances'][$glance], 'hidden' => false],
                $shown,
            );
            foreach (array_diff($reachable, $shown) as $rest) {
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
