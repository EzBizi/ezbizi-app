import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Project Management's stages (design 141, option 1), in the Jobs tab's
/// order: the five open ones, then Completed and Cancelled. The keys are the
/// server's (`ProjectStages::KEYS`); `jobs.pm_stages` maps each to statuses.
const pmStageKeys = [
  'in_progress',
  'waiting_review',
  'ready_to_schedule',
  'scheduled',
  'done_waiting_payment',
  'completed',
  'cancelled',
];

/// Drawn after the divider, muted: work that is over.
const pmClosedStages = {'completed', 'cancelled'};

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

  PmFilters copyWith({
    Object? areaId = _keep,
    String? q,
    Object? redo = _keep,
    bool? dispute,
    bool? stopped,
    bool? external,
    bool? secondVisit,
  }) => PmFilters(
    areaId: areaId == _keep ? this.areaId : areaId as String?,
    q: q ?? this.q,
    redo: redo == _keep ? this.redo : redo as bool?,
    dispute: dispute ?? this.dispute,
    stopped: stopped ?? this.stopped,
    external: external ?? this.external,
    secondVisit: secondVisit ?? this.secondVisit,
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
      other.secondVisit == secondVisit;

  @override
  int get hashCode =>
      Object.hash(areaId, q, redo, dispute, stopped, external, secondVisit);
}

const _keep = Object();

/// Where a Dashboard tile sends Jobs: a stage and the filters it counted
/// under. [serial] tells two taps on the same tile apart.
@immutable
class PmJobsIntent {
  const PmJobsIntent(this.stage, this.filters, this.serial);

  final String stage;
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
/// palette's hues by position, "No reason" neutral.
Color pmReasonColour(BuildContext context, int index, {bool none = false}) =>
    none
    ? ToneColours.of(context, Tone.neutral).ink
    : PersonColours.hue(context, index).ink;

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

/// The service area chips, All first, as one row. A name two franchises
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

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          chip(null, allLabel),
          for (final a in areas) chip(a.id, label(a)),
        ],
      ),
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
