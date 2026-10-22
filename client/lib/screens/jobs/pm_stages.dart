import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../widgets/search_picker.dart';

/// Project Management's stages (design 141, option 1), in the Jobs tab's
/// order. Open jobs only: Completed and Cancelled came out (Jose,
/// 2026-10-22: Project Management is about open, partly paid jobs). The keys are the
/// server's (`ProjectStages::KEYS`); `jobs.pm_stages` maps each to statuses.
const pmStageKeys = [
  'in_progress',
  'waiting_review',
  'ready_to_schedule',
  'scheduled',
  'done_waiting_payment',
];

String pmStageName(String key) => switch (key) {
  'in_progress' => 'In progress',
  'waiting_review' => 'Waiting review',
  'ready_to_schedule' => 'Ready to schedule',
  'scheduled' => 'Scheduled',
  'done_waiting_payment' => 'Done, waiting payment',
  'completed' => 'Completed',
  'cancelled' => 'Cancelled',
  _ => key,
};

/// Each stage's colour, one map for the Settings table's dots, the Jobs
/// tab's stage chips and the Dashboard's stage tiles (design 142): the
/// palette's hues, so it reads in light and dark.
const pmStageHues = {
  'in_progress': 0, // blue
  'waiting_review': 5, // orange
  'ready_to_schedule': 1, // teal
  'scheduled': 10, // sky
  'done_waiting_payment': 4, // gold
};

Color pmStageColour(BuildContext context, String key) =>
    PersonColours.hue(context, pmStageHues[key] ?? 9).ink;

/// A stage's colour as a small dot.
class PmStageDot extends StatelessWidget {
  const PmStageDot(this.stage, {super.key, this.size = 10});

  final String stage;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(
      color: pmStageColour(context, stage),
      shape: BoxShape.circle,
    ),
  );
}

/// The filters the Dashboard and Jobs share, sent as the server names them
/// (`stageFilters()` on JobsProjectManagementController).
@immutable
class PmFilters {
  const PmFilters({
    this.areaId,
    this.q = '',
    this.redo,
    this.dispute = false,
    this.stopped = false,
    this.external = false,
    this.secondVisit = false,
    this.waitingToStart = false,
  });

  /// A service area's id, or null for All.
  final String? areaId;
  final String q;

  /// True: redos only. False: one go only (the server's `redo=0`). Null: both.
  final bool? redo;
  final bool dispute;
  final bool stopped;
  final bool external;
  final bool secondVisit;

  /// Paid, not started, past the warning's days: the Dashboard's "See all"
  /// (`waiting_to_start=1&over_limit=1`, 0f181d521).
  final bool waitingToStart;

  PmFilters copyWith({
    Object? areaId = _keep,
    String? q,
    Object? redo = _keep,
    bool? dispute,
    bool? stopped,
    bool? external,
    bool? secondVisit,
    bool? waitingToStart,
  }) => PmFilters(
    areaId: areaId == _keep ? this.areaId : areaId as String?,
    q: q ?? this.q,
    redo: redo == _keep ? this.redo : redo as bool?,
    dispute: dispute ?? this.dispute,
    stopped: stopped ?? this.stopped,
    external: external ?? this.external,
    secondVisit: secondVisit ?? this.secondVisit,
    waitingToStart: waitingToStart ?? this.waitingToStart,
  );

  /// The query. [secondVisit] goes only where it is offered.
  Map<String, String> query({bool withSecondVisit = true}) => {
    'service_area_id': ?areaId,
    if (q.trim().isNotEmpty) 'q': q.trim(),
    if (redo != null) 'redo': redo! ? '1' : '0',
    if (dispute) 'dispute': '1',
    if (stopped) 'stopped': '1',
    if (external) 'external': '1',
    if (secondVisit && withSecondVisit) 'second_visit': '1',
    if (waitingToStart) ...{'waiting_to_start': '1', 'over_limit': '1'},
  };

  @override
  bool operator ==(Object other) =>
      other is PmFilters &&
      other.areaId == areaId &&
      other.q == q &&
      other.redo == redo &&
      other.dispute == dispute &&
      other.stopped == stopped &&
      other.external == external &&
      other.secondVisit == secondVisit &&
      other.waitingToStart == waitingToStart;

  @override
  int get hashCode => Object.hash(
    areaId,
    q,
    redo,
    dispute,
    stopped,
    external,
    secondVisit,
    waitingToStart,
  );
}

const _keep = Object();

/// Where a Dashboard tile sends Jobs: a stage and the filters it counted
/// under; no stage is every stage (the waiting-to-start "See all"). [serial]
/// tells two taps on the same tile apart.
@immutable
class PmJobsIntent {
  const PmJobsIntent(this.stage, this.filters, this.serial);

  final String? stage;
  final PmFilters filters;
  final int serial;
}

/// A service area, as GET /jobs's `meta.service_areas` lists them.
typedef PmArea = ({String id, String name, String franchiseId});

List<PmArea> pmAreas(Object? meta) {
  final areas = meta is Map ? meta['service_areas'] : null;
  return [
    if (areas is List)
      for (final a in areas)
        if (a is Map && a['id'] is String && a['name'] is String)
          (
            id: a['id'] as String,
            name: a['name'] as String,
            franchiseId: '${a['franchise_id']}',
          ),
  ];
}

/// The countdown's tone: past or at the limit is when the daily warning goes
/// (`WarnPaidJobsNotStarted`: days >= pm_warn_days), so red; two days or
/// fewer before it, amber.
Tone pmCountdownTone(int days, int limit) {
  if (days >= limit) return Tone.bad;
  if (limit - days <= 2) return Tone.caution;
  return Tone.info;
}

String pmCountdownText(int days, int limit) {
  final d = '$days ${days == 1 ? 'day' : 'days'}';
  if (days >= limit) return '$d · warned daily';
  return '$d · ${limit - days} left';
}

/// Days since payment against the limit, as a small bar and its words.
class PmCountdownBar extends StatelessWidget {
  const PmCountdownBar({
    super.key,
    required this.days,
    required this.limit,
    this.width = 90,
  });

  final int days;
  final int limit;
  final double width;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tone = pmCountdownTone(days, limit);
    final colours = ToneColours.of(context, tone);
    final share = limit <= 0 ? 1.0 : (days / limit).clamp(0.0, 1.0);

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: width,
          child: PmBar(segments: [(share, colours.ink)]),
        ),
        const SizedBox(width: 6),
        Flexible(
          child: Text(
            pmCountdownText(days, limit),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: tone == Tone.bad ? colours.ink : null,
              fontWeight: tone == Tone.bad ? FontWeight.w700 : null,
            ),
          ),
        ),
      ],
    );
  }
}

/// A thin bar of coloured shares, each 0–1 of the whole width, on the
/// neutral track.
class PmBar extends StatelessWidget {
  const PmBar({super.key, required this.segments, this.height = 8});

  final List<(double, Color)> segments;
  final double height;

  @override
  Widget build(BuildContext context) {
    final track = ToneColours.of(context, Tone.neutral).fill;
    final used = segments.fold<double>(0, (a, s) => a + s.$1).clamp(0.0, 1.0);
    final rest = ((1 - used) * 1000).round();

    return ClipRRect(
      borderRadius: BorderRadius.circular(height / 2),
      child: SizedBox(
        height: height,
        child: ColoredBox(
          color: track,
          child: Row(
            children: [
              for (final (share, colour) in segments)
                if ((share * 1000).round() > 0)
                  Expanded(
                    flex: (share * 1000).round(),
                    child: ColoredBox(color: colour),
                  ),
              if (rest > 0) Spacer(flex: rest),
            ],
          ),
        ),
      ),
    );
  }
}

/// A review reason's colour in the stacked bars and their legend: the
/// palette's hues by position.
///
/// "No reason" was the neutral grey, the family of the bar's own track. On
/// 2026-10-22 every live job waiting review had no reason, so every bar read
/// as an empty grey track (Jose: "bars dont have any collors?"). It takes a
/// hue like the rest; [none] is kept for callers.
Color pmReasonColour(BuildContext context, int index, {bool none = false}) =>
    PersonColours.hue(context, index).ink;

/// "Not set up", with the way to Settings, for a stage `jobs.pm_stages`
/// does not map.
class PmNotSetUp extends StatelessWidget {
  const PmNotSetUp({super.key, required this.stage, this.onSetUp});

  final String stage;
  final VoidCallback? onSetUp;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              '${pmStageName(stage)} is not set up.',
              textAlign: TextAlign.center,
              style: theme.textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              'Choose the statuses that put a job here in Settings.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            if (onSetUp != null) ...[
              const SizedBox(height: 12),
              OutlinedButton(
                key: const ValueKey('pm-jobs-setup'),
                onPressed: onSetUp,
                child: const Text('Set up in Settings'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// How many service areas still show as chips; more become one picker.
const pmAreaChipLimit = 6;

const _all = '__all__';

/// The service area chips, All first, wrapped onto lines; past
/// [pmAreaChipLimit] areas, one searchable picker instead. A name two franchises
/// share carries its franchise.
class PmAreaChips extends StatelessWidget {
  const PmAreaChips({
    super.key,
    required this.areas,
    required this.selected,
    required this.onSelected,
    this.franchiseName,
    this.allLabel = 'All',
  });

  final List<PmArea> areas;
  final String? selected;
  final ValueChanged<String?> onSelected;
  final String Function(String franchiseId)? franchiseName;
  final String allLabel;

  @override
  Widget build(BuildContext context) {
    final names = <String, int>{};
    for (final a in areas) {
      names[a.name] = (names[a.name] ?? 0) + 1;
    }
    String label(PmArea a) {
      if ((names[a.name] ?? 0) < 2) return a.name;
      final f = franchiseName?.call(a.franchiseId);
      return f == null || f.isEmpty ? a.name : '${a.name} · $f';
    }

    Widget chip(String? id, String text) => Padding(
      padding: const EdgeInsets.only(right: 6),
      child: ChoiceChip(
        key: ValueKey('pm-area-${id ?? 'all'}'),
        showCheckmark: false,
        selected: selected == id,
        label: Text(text),
        onSelected: (_) {
          if (selected != id) onSelected(id);
        },
      ),
    );

    // Jose, 2026-10-22, "overflow issue as well": dozens of areas in one row
    // ran past the screen edge and under the search field. Past
    // [pmAreaChipLimit] the chips become one picker; up to it they wrap.
    if (areas.length > pmAreaChipLimit) {
      final byLabel = [for (final a in areas) (id: a.id, text: label(a))]
        ..sort((x, y) => x.text.toLowerCase().compareTo(y.text.toLowerCase()));
      return Align(
        alignment: Alignment.centerLeft,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 320),
          child: SearchPickerField<String>(
            key: const ValueKey('pm-area-picker'),
            value: selected ?? _all,
            label: 'Service area',
            isDense: true,
            options: [
              SearchOption(value: _all, label: allLabel),
              for (final a in byLabel) SearchOption(value: a.id, label: a.text),
            ],
            onChanged: (v) {
              final id = v == null || v == _all ? null : v;
              if (id != selected) onSelected(id);
            },
          ),
        ),
      );
    }

    return Wrap(
      runSpacing: 6,
      children: [
        chip(null, allLabel),
        for (final a in areas) chip(a.id, label(a)),
      ],
    );
  }
}

/// Redo / One go, Disputes, Stopped payments, External and, where it is
/// offered, Needs 2nd visit.
class PmFlagChips extends StatelessWidget {
  const PmFlagChips({
    super.key,
    required this.filters,
    required this.onChanged,
    this.secondVisit = true,
  });

  final PmFilters filters;
  final ValueChanged<PmFilters> onChanged;
  final bool secondVisit;

  @override
  Widget build(BuildContext context) {
    Widget chip(String key, String label, bool on, PmFilters next) =>
        FilterChip(
          key: ValueKey('pm-flag-$key'),
          selected: on,
          label: Text(label),
          onSelected: (_) => onChanged(next),
        );

    final f = filters;
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        if (secondVisit)
          chip(
            'second_visit',
            'Needs 2nd visit',
            f.secondVisit,
            f.copyWith(secondVisit: !f.secondVisit),
          ),
        chip(
          'external',
          'External',
          f.external,
          f.copyWith(external: !f.external),
        ),
        chip(
          'redo',
          'Redo',
          f.redo == true,
          f.copyWith(redo: f.redo == true ? null : true),
        ),
        chip(
          'one_go',
          'One go',
          f.redo == false,
          f.copyWith(redo: f.redo == false ? null : false),
        ),
        chip('dispute', 'Disputes', f.dispute, f.copyWith(dispute: !f.dispute)),
        chip(
          'stopped',
          'Stopped payments',
          f.stopped,
          f.copyWith(stopped: !f.stopped),
        ),
      ],
    );
  }
}

/// Which jobs Project Management is about, for the place being viewed
/// (`jobs.pm_scope_statuses`, empty: any; `jobs.pm_partly_paid_only`).
@immutable
class PmScope {
  const PmScope({
    this.statuses = const [],
    this.partlyPaidOnly = false,
    this.place = '',
  });

  /// The statuses' own words, as Settings names them.
  final List<String> statuses;
  final bool partlyPaidOnly;

  /// The top-bar place these settings are read for: "Company · Branch".
  final String place;
}

/// "Showing partly paid jobs in Open, Scheduled": what [PmScope] keeps in.
String pmScopeSentence(PmScope scope) {
  final s = scope.statuses;
  final which = s.isEmpty
      ? 'in every status'
      : s.length > 3
      ? 'in ${s.length} statuses'
      : 'in ${s.join(', ')}';
  return 'Showing ${scope.partlyPaidOnly ? 'partly paid ' : ''}jobs $which';
}

/// The scope as one line, with the place and the way to its Settings card.
/// Dashboard, Jobs and Assignments draw it above their toolbar.
class PmScopeLine extends StatelessWidget {
  const PmScopeLine({super.key, required this.scope, this.onChange});

  final PmScope scope;
  final VoidCallback? onChange;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Wrap(
      key: const ValueKey('pm-scope-line'),
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(
          [
            pmScopeSentence(scope),
            if (scope.place.isNotEmpty) scope.place,
          ].join(' · '),
          key: const ValueKey('pm-scope-text'),
          style: muted,
        ),
        if (onChange != null) ...[
          Text(' · ', style: muted),
          InkWell(
            key: const ValueKey('pm-scope-change'),
            borderRadius: BorderRadius.circular(4),
            onTap: onChange,
            child: Text(
              'Change',
              style: muted?.copyWith(
                color: theme.colorScheme.primary,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

/// The toolbar every Project Management list tab draws, in one order:
/// search first, the service area beside it, the filter chips under them.
/// Under 600 wide each takes its own line.
class PmToolbar extends StatelessWidget {
  const PmToolbar({
    super.key,
    required this.search,
    this.areas,
    this.filters,
    this.above,
  });

  final Widget search;
  final Widget? areas;
  final Widget? filters;

  /// Drawn first, on its own line: the scope line.
  final Widget? above;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < 600;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (above != null) ...[above!, const SizedBox(height: 8)],
            if (phone) ...[
              search,
              if (areas != null) ...[const SizedBox(height: 8), areas!],
            ] else
              Wrap(
                spacing: 12,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  SizedBox(width: 300, child: search),
                  if (areas != null)
                    ConstrainedBox(
                      constraints: BoxConstraints(
                        maxWidth: (constraints.maxWidth - 312).clamp(
                          120.0,
                          double.infinity,
                        ),
                      ),
                      child: areas!,
                    ),
                ],
              ),
            if (filters != null) ...[const SizedBox(height: 8), filters!],
          ],
        );
      },
    );
  }
}

/// The one search hint Dashboard, Jobs and Assignments share.
const pmJobSearchHint = 'Job, customer, address, phone or tech';
