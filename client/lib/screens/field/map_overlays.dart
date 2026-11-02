import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:latlong2/latlong.dart';

import '../../core/theme.dart';
import '../../core/timestamps.dart';
import 'field_status.dart';

/// The pieces drawn over the Field map: the floating list (people and jobs),
/// the technician bar, and the job card a dispatcher answers an ETA call from.
/// Kept apart from map_tab.dart so the map's state stays readable.

// ── Pins ──────────────────────────────────────────────────────────────────

class FieldPersonPin extends StatelessWidget {
  const FieldPersonPin({
    super.key,
    required this.name,
    required this.colour,
    required this.selected,
    this.dashed = false,
  });

  final String name;
  final Color colour;
  final bool selected;

  /// Lost signal: the ring is dashed.
  final bool dashed;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    // A round ring with initials, never a job's filled square; the full
    // name only when selected or hovered, so pins in one town do not stack
    // their labels on each other (Jose, 2026-10-26).
    final ring = CustomPaint(
      painter: FieldRingPainter(
        colour: colour,
        fill: scheme.surface,
        dashed: dashed,
        width: 3,
      ),
      child: SizedBox.square(
        dimension: selected ? 34 : 28,
        child: Center(
          child: Text(
            fieldInitials(name),
            style: TextStyle(
              fontSize: selected ? 12 : 10,
              fontWeight: FontWeight.w700,
              color: scheme.onSurface,
            ),
          ),
        ),
      ),
    );
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Tooltip(message: name, child: ring),
        if (selected) _Label(text: name, bold: true, scheme: scheme),
      ],
    );
  }
}

class FieldJobPin extends StatelessWidget {
  const FieldJobPin({
    super.key,
    required this.label,
    required this.colour,
    required this.selected,
  });

  final String label;
  final Color colour;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          padding: EdgeInsets.all(selected ? 5 : 3),
          decoration: BoxDecoration(
            color: colour,
            borderRadius: BorderRadius.circular(6),
            border: Border.all(color: scheme.surface, width: 2),
          ),
          child: Icon(
            Icons.home_repair_service,
            size: selected ? 18 : 14,
            color: fieldInk(colour),
          ),
        ),
        if (selected) _Label(text: label, bold: true, scheme: scheme),
      ],
    );
  }
}

class _Label extends StatelessWidget {
  const _Label({required this.text, required this.bold, required this.scheme});

  final String text;
  final bool bold;
  final ColorScheme scheme;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
    decoration: BoxDecoration(
      color: scheme.surface.withValues(alpha: 0.9),
      borderRadius: BorderRadius.circular(4),
    ),
    child: Text(
      text,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: TextStyle(
        fontSize: 11,
        fontWeight: bold ? FontWeight.w600 : FontWeight.w400,
        color: scheme.onSurface,
      ),
    ),
  );
}

// ── The floating list ─────────────────────────────────────────────────────

class FieldMapPanel extends StatelessWidget {
  const FieldMapPanel({
    super.key,
    required this.people,
    required this.jobs,
    required this.showJobs,
    required this.tab,
    required this.onTab,
    required this.query,
    required this.onQuery,
    required this.selectedPersonId,
    required this.selectedJobId,
    required this.personColour,
    required this.onPerson,
    required this.onJob,
    required this.onRefresh,
    required this.onClose,
    required this.day,
    required this.trailEmpty,
    required this.onPickDay,
    required this.mapDay,
    required this.onPrevDay,
    required this.onNextDay,
    required this.onToday,
  });

  final DateTime mapDay;
  final VoidCallback onPrevDay;
  final VoidCallback onNextDay;

  /// Null when the map already shows today.
  final VoidCallback? onToday;

  final List<Map<String, dynamic>> people;
  final List<Map<String, dynamic>> jobs;
  final bool showJobs;
  final String tab;
  final ValueChanged<String> onTab;
  final String query;
  final ValueChanged<String> onQuery;
  final String? selectedPersonId;
  final String? selectedJobId;
  final Color Function(Map<String, dynamic>) personColour;
  final void Function(Map<String, dynamic>) onPerson;
  final void Function(Map<String, dynamic>) onJob;
  final VoidCallback onRefresh;
  final VoidCallback onClose;
  final DateTime? day;
  final bool trailEmpty;
  final VoidCallback onPickDay;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final reporting = people.where((p) => p['fresh'] == true).length;
    final q = query.trim().toLowerCase();
    final shownJobs = [
      for (final j in jobs)
        if (q.isEmpty ||
            '${j['number'] ?? ''} ${j['customer'] ?? ''} ${j['title'] ?? ''} ${j['address'] ?? ''}'
                .toLowerCase()
                .contains(q))
          j,
    ];
    final jobsTab = showJobs && tab == 'jobs';
    // People are searched by name; the list grows as technicians join.
    final shownPeople = [
      for (final p in fieldSortByAttention(people))
        if (q.isEmpty ||
            '${(p['user'] as Map)['name']}'.toLowerCase().contains(q))
          p,
    ];

    return Material(
      elevation: 3,
      color: scheme.surface.withValues(alpha: 0.96),
      borderRadius: BorderRadius.circular(12),
      clipBehavior: Clip.antiAlias,
      child: SizedBox(
        width: 264,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 6, 4, 4),
              child: Row(
                children: [
                  if (showJobs)
                    Expanded(
                      child: SegmentedButton<String>(
                        showSelectedIcon: false,
                        style: const ButtonStyle(
                          visualDensity: VisualDensity.compact,
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                        segments: [
                          ButtonSegment(
                            value: 'people',
                            label: Text('People ${people.length}'),
                          ),
                          ButtonSegment(
                            value: 'jobs',
                            label: Text('Jobs ${jobs.length}'),
                          ),
                        ],
                        selected: {tab},
                        onSelectionChanged: (s) => onTab(s.first),
                      ),
                    )
                  else
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.only(left: 4),
                        child: Text(
                          'Tracked',
                          style: theme.textTheme.titleSmall,
                        ),
                      ),
                    ),
                  IconButton(
                    tooltip: 'Refresh',
                    visualDensity: VisualDensity.compact,
                    icon: const Icon(Icons.refresh, size: 18),
                    onPressed: onRefresh,
                  ),
                  IconButton(
                    tooltip: 'Hide',
                    visualDensity: VisualDensity.compact,
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: onClose,
                  ),
                ],
              ),
            ),
            // The map's day: that day's jobs and routes.
            Padding(
              key: const ValueKey('tour-field-map-day'),
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 2),
              child: Row(
                children: [
                  IconButton(
                    tooltip: 'Previous day',
                    visualDensity: VisualDensity.compact,
                    icon: const Icon(Icons.chevron_left, size: 20),
                    onPressed: onPrevDay,
                  ),
                  Expanded(
                    child: TextButton(
                      onPressed: onPickDay,
                      child: Text(
                        fieldLongDay(mapDay),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ),
                  if (onToday != null)
                    TextButton(onPressed: onToday, child: const Text('Today')),
                  IconButton(
                    tooltip: 'Next day',
                    visualDensity: VisualDensity.compact,
                    icon: const Icon(Icons.chevron_right, size: 20),
                    onPressed: onNextDay,
                  ),
                ],
              ),
            ),
            Padding(
              key: const ValueKey('tour-field-map-search'),
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 6),
              child: TextField(
                key: ValueKey(jobsTab),
                onChanged: onQuery,
                decoration: InputDecoration(
                  isDense: true,
                  prefixIcon: const Icon(Icons.search, size: 18),
                  hintText: jobsTab
                      ? 'Job number or customer'
                      : 'Find a person',
                ),
              ),
            ),
            if (!jobsTab)
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
                child: Text(
                  '$reporting of ${people.length} reporting now',
                  style: muted,
                ),
              ),
            const Divider(height: 1),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                padding: EdgeInsets.zero,
                children: jobsTab
                    ? [
                        if (shownJobs.isEmpty)
                          Padding(
                            padding: const EdgeInsets.all(12),
                            child: Text(
                              jobs.isEmpty
                                  ? 'No jobs today in this view.'
                                  : 'No job matches.',
                              style: muted,
                            ),
                          ),
                        for (final j in shownJobs)
                          _JobRow(
                            job: j,
                            selected: j['job_id'] == selectedJobId,
                            onTap: () => onJob(j),
                          ),
                      ]
                    : [
                        if (people.isEmpty)
                          Padding(
                            padding: const EdgeInsets.all(12),
                            child: Text(
                              'Nobody you can see has reported yet.',
                              style: muted,
                            ),
                          ),
                        if (people.isNotEmpty && shownPeople.isEmpty)
                          Padding(
                            padding: const EdgeInsets.all(12),
                            child: Text('Nobody matches.', style: muted),
                          ),
                        for (final p in shownPeople)
                          _PersonRow(
                            person: p,
                            colour: personColour(p),
                            selected:
                                (p['user'] as Map)['id'] == selectedPersonId,
                            onTap: () => onPerson(p),
                            day: (p['user'] as Map)['id'] == selectedPersonId
                                ? day
                                : null,
                            trailEmpty: trailEmpty,
                            onPickDay: onPickDay,
                          ),
                      ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PersonRow extends StatelessWidget {
  const _PersonRow({
    required this.person,
    required this.colour,
    required this.selected,
    required this.onTap,
    required this.day,
    required this.trailEmpty,
    required this.onPickDay,
  });

  final Map<String, dynamic> person;
  final Color colour;
  final bool selected;
  final VoidCallback onTap;
  final DateTime? day;
  final bool trailEmpty;
  final VoidCallback onPickDay;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final since = fieldParse(person['since']);
    final seen = fieldParse(person['last_seen_at']);
    final speed = person['speed_mph'];
    final job = person['at_job'] as Map?;

    return _Selectable(
      selected: selected,
      onTap: onTap,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _Dot(
            colour: colour,
            dashed: fieldPersonState(person) == FieldPersonState.lostSignal,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${(person['user'] as Map)['name']}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                if (since != null)
                  Text(
                    'Since ${fieldClock(since)} · ${fieldSpan(DateTime.now().difference(since))}',
                    style: muted,
                  ),
                if (seen == null)
                  Text(
                    fieldPersonState(person) == FieldPersonState.offline
                        ? 'Offline · has not reported yet'
                        : 'Has not reported yet',
                    style: muted?.copyWith(
                      color: fieldPersonState(person).text(context),
                    ),
                  )
                else
                  Text(
                    [
                      'Seen ${fieldAgo(seen)}',
                      if (speed is num && speed > 0) '$speed mph',
                      '${person['distance_mi'] ?? 0} mi',
                    ].join(' · '),
                    style: muted,
                  ),
                if (job != null)
                  Text(
                    'At ${job['title'] ?? job['number'] ?? 'a job'}',
                    style: muted?.copyWith(
                      color: fieldText(context, EzBiziTheme.fieldBlue),
                    ),
                  ),
                if (person['stopped'] == true)
                  Text(
                    'Stopped too long',
                    style: muted?.copyWith(
                      color: FieldPersonState.stopped.text(context),
                    ),
                  ),
                if (person['silent'] == true)
                  Text(
                    'Lost signal',
                    style: muted?.copyWith(
                      color: FieldPersonState.lostSignal.text(context),
                    ),
                  ),
                if (seen != null &&
                    fieldPersonState(person) == FieldPersonState.offline)
                  Text('Offline', style: muted),
                if (day != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: InkWell(
                      onTap: onPickDay,
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.timeline,
                            size: 14,
                            color: fieldText(context, EzBiziTheme.fieldBlue),
                          ),
                          const SizedBox(width: 4),
                          Flexible(
                            child: Text(
                              'Route ${fieldDayLabel(day!)}${trailEmpty ? ' · none' : ''}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: muted?.copyWith(
                                color: fieldText(
                                  context,
                                  EzBiziTheme.fieldBlue,
                                ),
                              ),
                            ),
                          ),
                          Icon(
                            Icons.arrow_drop_down,
                            size: 16,
                            color: fieldText(context, EzBiziTheme.fieldBlue),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _JobRow extends StatelessWidget {
  const _JobRow({
    required this.job,
    required this.selected,
    required this.onTap,
  });

  final Map<String, dynamic> job;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final tech = (job['tech'] as Map?)?['name'];

    return _Selectable(
      selected: selected,
      onTap: onTap,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 58,
            child: Text(
              fieldWallTime(job['scheduled_for'] as String?),
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${job['customer'] ?? job['title'] ?? 'Job'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                Text(
                  [
                    if (job['number'] != null) '#${job['number']}',
                    tech ?? 'Unassigned',
                    if (job['lat'] == null) 'not on the map',
                  ].join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: muted,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Selectable extends StatelessWidget {
  const _Selectable({
    required this.selected,
    required this.onTap,
    required this.child,
  });

  final bool selected;
  final VoidCallback onTap;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Material(
      color: selected
          ? scheme.primaryContainer.withValues(alpha: 0.5)
          : scheme.surface.withValues(alpha: 0),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: child,
        ),
      ),
    );
  }
}

class _Dot extends StatelessWidget {
  const _Dot({required this.colour, this.dashed = false});

  final Color colour;
  final bool dashed;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 5),
    child: dashed
        ? CustomPaint(
            key: const ValueKey('field-dot-dashed'),
            painter: FieldRingPainter(
              colour: colour,
              fill: colour.withValues(alpha: 0.35),
              dashed: true,
              width: 2,
            ),
            child: const SizedBox.square(dimension: 10),
          )
        : Container(
            width: 10,
            height: 10,
            decoration: BoxDecoration(color: colour, shape: BoxShape.circle),
          ),
  );
}

// ── The technician bar ────────────────────────────────────────────────────

/// Horizontal, along the top of the map, like A1 Tools' but lighter: every
/// fact is one short chip, and a fact the phone did not send is left out.
class FieldPersonBar extends StatelessWidget {
  const FieldPersonBar({
    super.key,
    required this.person,
    required this.colour,
    required this.onClose,
  });

  final Map<String, dynamic> person;
  final Color colour;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final device = (person['device'] as Map?) ?? const {};
    final clocked = fieldParse(person['clocked_in_at']);
    final since = fieldParse(person['since']);
    final seen = fieldParse(person['last_seen_at']);
    final battery = device['battery_pct'];
    final next = person['next_job'] as Map?;

    // Colour says something (Jose, 2026-09-30): green good, yellow watch,
    // red a problem worth a call, blue plain information, grey detail.
    // As TEXT it is the tone ink, which reads on a light panel too.
    final seenMinutes = seen == null
        ? null
        : DateTime.now().difference(seen).inMinutes;
    final accuracy = person['accuracy_m'];
    final moving =
        person['speed_mph'] is num && (person['speed_mph'] as num) > 0;
    final charging = device['charging'] == true;

    final facts = <(IconData, String, Color)>[
      if (clocked != null)
        (
          Icons.login,
          'In ${fieldClock(clocked)} · ${fieldSpan(DateTime.now().difference(clocked))}',
          fieldText(context, EzBiziTheme.fieldBlue),
        )
      else if (since != null)
        (
          Icons.play_arrow,
          'Since ${fieldClock(since)}',
          fieldText(context, EzBiziTheme.fieldBlue),
        ),
      if (battery is num)
        (
          charging
              ? Icons.battery_charging_full
              : battery <= 20
              ? Icons.battery_alert
              : Icons.battery_std,
          '$battery%${charging ? ' charging' : ''}',
          battery <= 20 && !charging
              ? fieldText(context, EzBiziTheme.fieldRed)
              : charging || battery > 50
              ? fieldText(context, EzBiziTheme.fieldGreen)
              : fieldText(context, EzBiziTheme.fieldYellow),
        ),
      if (person['speed_mph'] is num)
        (
          Icons.speed,
          moving ? '${person['speed_mph']} mph now' : 'Not moving',
          moving
              ? fieldText(context, EzBiziTheme.fieldGreen)
              : scheme.onSurfaceVariant,
        ),
      if (person['avg_speed_mph'] is num)
        (
          Icons.show_chart,
          '${person['avg_speed_mph']} avg · ${person['top_speed_mph']} top',
          fieldText(context, EzBiziTheme.fieldBlue),
        ),
      (
        Icons.route,
        '${person['distance_mi'] ?? 0} mi today',
        fieldText(context, EzBiziTheme.fieldBlue),
      ),
      if (seen != null)
        (
          Icons.gps_fixed,
          'Seen ${fieldAgo(seen)}',
          seenMinutes! > 30
              ? fieldText(context, EzBiziTheme.fieldRed)
              : seenMinutes <= 5
              ? fieldText(context, EzBiziTheme.fieldGreen)
              : fieldText(context, EzBiziTheme.fieldYellow),
        ),
      if (accuracy is num)
        (
          Icons.my_location,
          '±$accuracy m',
          accuracy > 100
              ? fieldText(context, EzBiziTheme.fieldRed)
              : accuracy > 20
              ? fieldText(context, EzBiziTheme.fieldYellow)
              : fieldText(context, EzBiziTheme.fieldGreen),
        ),
      if (next != null)
        (
          Icons.event,
          'Next ${next['customer'] ?? '#${next['number']}'} ${fieldWallTime(next['scheduled_for'] as String?)}',
          fieldText(context, EzBiziTheme.fieldBlue),
        ),
      if (device['model'] != null || device['os_version'] != null)
        (
          device['platform'] == 'ios'
              ? Icons.phone_iphone
              : Icons.phone_android,
          [
            device['model'],
            device['os_version'],
          ].whereType<String>().join(' · '),
          scheme.onSurfaceVariant,
        ),
      if (device['app_version'] != null)
        (
          Icons.sell_outlined,
          'v${device['app_version']}',
          scheme.onSurfaceVariant,
        ),
    ];

    return Material(
      elevation: 3,
      color: scheme.surface.withValues(alpha: 0.96),
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 6, 4, 6),
        child: Row(
          children: [
            _Dot(
              colour: colour,
              dashed: fieldPersonState(person) == FieldPersonState.lostSignal,
            ),
            const SizedBox(width: 8),
            Text(
              '${(person['user'] as Map)['name']}',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Wrap(
                spacing: 14,
                runSpacing: 2,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  for (final (icon, text, tint) in facts)
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(icon, size: 15, color: tint),
                        const SizedBox(width: 4),
                        Flexible(
                          child: Text(
                            text,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: tint,
                            ),
                          ),
                        ),
                      ],
                    ),
                ],
              ),
            ),
            IconButton(
              tooltip: 'Close',
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.close, size: 18),
              onPressed: onClose,
            ),
          ],
        ),
      ),
    );
  }
}

// ── The job card ──────────────────────────────────────────────────────────

/// A caller asks "when will they be here?": the job, its nearest people by
/// drive time, and once one is picked, the minutes and a sentence to read out.
class FieldJobCard extends StatelessWidget {
  const FieldJobCard({
    super.key,
    required this.job,
    required this.eta,
    required this.route,
    required this.loading,
    required this.onPerson,
    required this.onClose,
  });

  final Map<String, dynamic> job;
  final Map<String, dynamic>? eta;
  final Map<String, dynamic>? route;
  final bool loading;
  final void Function(String userId) onPerson;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final candidates = ((eta?['candidates'] as List?) ?? const [])
        .cast<Map<String, dynamic>>();
    final routeFor = (route?['user'] as Map?)?['id'];
    final reason = route?['reason'] ?? eta?['reason'];

    return Material(
      elevation: 3,
      color: scheme.surface.withValues(alpha: 0.97),
      borderRadius: BorderRadius.circular(12),
      clipBehavior: Clip.antiAlias,
      child: SizedBox(
        width: 300,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 4, 10),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${job['customer'] ?? job['title'] ?? 'Job'}',
                          style: theme.textTheme.titleSmall,
                        ),
                        Text(
                          [
                            if (job['number'] != null) '#${job['number']}',
                            'Booked ${fieldWallTime(job['scheduled_for'] as String?)}',
                            if (job['status'] != null) '${job['status']}',
                          ].join(' · '),
                          style: muted,
                        ),
                        if (job['address'] != null)
                          Text('${job['address']}', style: muted),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: 'Close',
                    visualDensity: VisualDensity.compact,
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: onClose,
                  ),
                ],
              ),
              const SizedBox(height: 8),
              if (route != null && route!['minutes'] != null) ...[
                Container(
                  padding: const EdgeInsets.all(10),
                  margin: const EdgeInsets.only(right: 8, bottom: 8),
                  decoration: BoxDecoration(
                    color: scheme.primaryContainer,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${route!['minutes']} min · ${route!['miles']} mi · arrives ${route!['arrive_at']}',
                        style: theme.textTheme.titleSmall?.copyWith(
                          color: scheme.onPrimaryContainer,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${route!['say']}',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onPrimaryContainer,
                        ),
                      ),
                      Align(
                        alignment: Alignment.centerRight,
                        child: TextButton.icon(
                          icon: const Icon(Icons.copy, size: 16),
                          label: const Text('Copy'),
                          onPressed: () {
                            Clipboard.setData(
                              ClipboardData(text: '${route!['say']}'),
                            );
                            ScaffoldMessenger.maybeOf(context)?.showSnackBar(
                              const SnackBar(content: Text('Copied.')),
                            );
                          },
                        ),
                      ),
                    ],
                  ),
                ),
              ],
              if (reason != null)
                Padding(
                  padding: const EdgeInsets.only(right: 8, bottom: 6),
                  child: Text('$reason', style: muted),
                ),
              Text('Nearest now', style: theme.textTheme.labelMedium),
              if (loading)
                const Padding(
                  padding: EdgeInsets.all(8),
                  child: LinearProgressIndicator(),
                )
              else if (eta?['placed'] == false)
                const SizedBox.shrink()
              else if (candidates.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text('Nobody is reporting right now.', style: muted),
                ),
              for (final c in candidates)
                InkWell(
                  onTap: () => onPerson('${(c['user'] as Map)['id']}'),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 5),
                    child: Row(
                      children: [
                        Icon(
                          (c['user'] as Map)['id'] == routeFor
                              ? Icons.radio_button_checked
                              : Icons.radio_button_unchecked,
                          size: 16,
                          color: scheme.primary,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            '${(c['user'] as Map)['name']}${c['assigned'] == true ? ' · assigned' : ''}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        Text(
                          c['minutes'] != null
                              ? '${c['minutes']} min · ${c['miles']} mi'
                              : '${c['miles']} mi',
                          style: muted,
                        ),
                        const SizedBox(width: 8),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Headquarters ─────────────────────────────────────────────────────────

/// A company's address on the map, with its logo (Jose, 2026-10-01).
class FieldPlacePin extends StatelessWidget {
  const FieldPlacePin({super.key, required this.name, required this.logo});

  final String name;
  final Widget logo;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 36,
          height: 36,
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            color: scheme.surface,
            shape: BoxShape.circle,
            border: Border.all(color: scheme.onSurface, width: 2),
          ),
          child: ClipOval(child: Center(child: logo)),
        ),
        _Label(text: name, bold: true, scheme: scheme),
      ],
    );
  }
}

// ── The legend ────────────────────────────────────────────────────────────

/// What each colour on the map means, along the bottom (Jose, 2026-09-30:
/// "I don't understand the difference in colors of each icon"). The colours
/// here are read from the same scheme slots the pins use, so the two cannot
/// drift apart.
class FieldMapLegend extends StatelessWidget {
  const FieldMapLegend({super.key, required this.showJobs, this.onClose});

  final bool showJobs;

  /// Set on a phone, where the legend opens from a button and folds back.
  final VoidCallback? onClose;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final text = theme.textTheme.bodySmall;

    Widget job(Color c) => Container(
      padding: const EdgeInsets.all(2),
      decoration: BoxDecoration(
        color: c,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Icon(Icons.home_repair_service, size: 10, color: fieldInk(c)),
    );
    Widget line(Color c, {bool dashed = false}) => SizedBox(
      width: 22,
      child: Row(
        children: [
          for (var i = 0; i < (dashed ? 3 : 1); i++) ...[
            Expanded(child: Container(height: 3, color: c)),
            if (dashed && i < 2) const SizedBox(width: 3),
          ],
        ],
      ),
    );

    final items = <(Widget, String)>[
      for (final st in const [
        FieldPersonState.ongoing,
        FieldPersonState.stopped,
        FieldPersonState.notReported,
        FieldPersonState.lostSignal,
        FieldPersonState.offline,
      ])
        (
          FieldStateRing(
            key: ValueKey('field-legend-${st.name}'),
            state: st,
            size: 14,
          ),
          st.label,
        ),
      (
        Container(
          width: 14,
          height: 14,
          decoration: BoxDecoration(
            color: scheme.surface,
            shape: BoxShape.circle,
            border: Border.all(color: scheme.onSurface, width: 2),
          ),
        ),
        'Headquarters',
      ),
      if (showJobs) ...[
        (job(EzBiziTheme.fieldBlue), 'Job with a technician'),
        (job(fieldUnassignedJob(context)), 'Job not assigned'),
        (job(scheme.onSurface), 'Job you opened'),
      ],
      (line(EzBiziTheme.fieldBlue), 'Route today'),
      if (showJobs)
        (line(EzBiziTheme.fieldGreen, dashed: true), 'Drive to the job'),
    ];

    return Material(
      color: scheme.surface.withValues(alpha: 0.92),
      borderRadius: onClose == null
          ? const BorderRadius.only(topRight: Radius.circular(10))
          : BorderRadius.circular(10),
      elevation: onClose == null ? 0 : 3,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 5, 8, 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Flexible(
              child: Wrap(
                spacing: 14,
                runSpacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  for (final (mark, label) in items)
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        mark,
                        const SizedBox(width: 5),
                        Text(label, style: text),
                      ],
                    ),
                ],
              ),
            ),
            if (onClose != null)
              IconButton(
                tooltip: 'Hide',
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.close, size: 16),
                onPressed: onClose,
              ),
          ],
        ),
      ),
    );
  }
}

// ── Small shared helpers ──────────────────────────────────────────────────

DateTime? fieldParse(Object? iso) =>
    iso is String ? DateTime.tryParse(iso)?.toLocal() : null;

String fieldClock(DateTime t) {
  final h = t.hour % 12 == 0 ? 12 : t.hour % 12;
  return '$h:${t.minute.toString().padLeft(2, '0')} ${t.hour < 12 ? 'AM' : 'PM'}';
}

/// A booked time as written at the franchise: 9:00 there is 9:00 here.
String fieldWallTime(String? iso) {
  if (iso == null || iso.length < 16) return '';
  final h = int.tryParse(iso.substring(11, 13)) ?? 0;
  return '${h % 12 == 0 ? 12 : h % 12}:${iso.substring(14, 16)} ${h < 12 ? 'AM' : 'PM'}';
}

String fieldSpan(Duration d) => d.inHours > 0
    ? '${d.inHours}h ${d.inMinutes % 60}m'
    : '${d.inMinutes.clamp(0, 59)}m';

String fieldAgo(DateTime t) {
  final d = DateTime.now().difference(t);
  if (d.inMinutes < 1) return 'just now';
  if (d.inMinutes < 60) return '${d.inMinutes} min ago';
  if (d.inHours < 24) return '${d.inHours}h ago';

  return '${t.month}/${t.day}';
}

String fieldDayLabel(DateTime d) {
  final now = DateTime.now();
  return d.year == now.year && d.month == now.month && d.day == now.day
      ? 'today'
      : '${d.month}/${d.day}';
}

/// Google's encoded polyline format, as the Routes API returns the road.
List<LatLng> decodePolyline(String encoded) {
  final points = <LatLng>[];
  var index = 0, lat = 0, lng = 0;
  while (index < encoded.length) {
    for (var axis = 0; axis < 2; axis++) {
      var shift = 0, result = 0, b = 0;
      do {
        b = encoded.codeUnitAt(index++) - 63;
        result |= (b & 0x1f) << shift;
        shift += 5;
      } while (b >= 0x20 && index < encoded.length);
      // Not `~(result >> 1)`: on the web `~` is unsigned 32-bit, so every
      // negative delta (any western longitude) became a huge positive one.
      final delta = (result & 1) != 0 ? -(result >> 1) - 1 : result >> 1;
      if (axis == 0) {
        lat += delta;
      } else {
        lng += delta;
      }
    }
    points.add(LatLng(lat / 1e5, lng / 1e5));
  }
  return points;
}

/// An unassigned job's pin (Jose, 2026-10-30): neutral grey, so it cannot be
/// mistaken for an assigned (blue) job or a lost-signal (yellow) person.
Color fieldUnassignedJob(BuildContext context) =>
    Theme.of(context).colorScheme.outline;

/// Dark ink on a light pin (yellow, or the "opened" marker in dark mode),
/// white on the others, so the job icon is always readable.
Color fieldInk(Color fill) => fill.computeLuminance() > 0.4
    ? EzBiziTheme.fieldInkDark
    : EzBiziTheme.fieldInkLight;

/// The readable version of a map colour, for words and small icons on a
/// panel. The map colours are loud enough for pins on tiles and too light
/// for text on white (yellow is 1.5:1); the tone inks are AA in both themes
/// (UI/UX, 2026-09-30).
Color fieldText(BuildContext context, Color field) => ToneColours.of(
  context,
  field == EzBiziTheme.fieldGreen
      ? Tone.ok
      : field == EzBiziTheme.fieldRed
      ? Tone.bad
      : field == EzBiziTheme.fieldYellow
      ? Tone.caution
      : Tone.info,
).ink;

/// "Wed 10/1", and "Today" for today, for the map's date control.
String fieldLongDay(DateTime d) {
  final label = '${shortWeekdays[d.weekday - 1]} ${d.month}/${d.day}';
  return fieldDayLabel(d) == 'today' ? 'Today · $label' : label;
}
