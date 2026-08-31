import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';

/// Capacity settings — the area rail, the pattern overview, and one area's grid.
///
/// design: docs/previews/scheduling-layouts.html #7 (#8 selected, #9 at 375px)
///
/// ── Two things this screen must not do ───────────────────────────
///
/// CLOSING A DAY DELETES ITS SLOTS. It never writes zero-capacity rows. A day
/// with no rows is closed; a day with rows is open. If closing ever wrote
/// zeros, "we do not work Sundays" and "we work Sundays and are full" would
/// become one stored state and nothing would error. The server refuses a zero
/// as well — a layout can decline to OFFER it, only the write path can decline
/// to ACCEPT it, and both halves are needed.
///
/// APPLYING A PATTERN NAMES WHAT IT WILL OVERWRITE FIRST, with the values, and
/// then applies to every area or none. A generic "this will overwrite existing
/// settings" is what people click through, and clicking through it is the
/// silent flatten the confirmation exists to prevent.
class CapacitySettings extends StatefulWidget {
  const CapacitySettings({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  static const double splitAbove = 600;

  @override
  State<CapacitySettings> createState() => _CapacitySettingsState();
}

class _CapacitySettingsState extends State<CapacitySettings> {
  /// Monday first, because the design's week reads Mon–Sun. The VALUES stay
  /// Carbon's weekday numbers (0 = Sunday) so nothing translates on the wire.
  static const _week = <(int, String)>[
    (1, 'Mon'), (2, 'Tue'), (3, 'Wed'), (4, 'Thu'), (5, 'Fri'), (6, 'Sat'), (0, 'Sun'),
  ];

  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _areas = [];
  Map<String, dynamic>? _pattern;

  /// null means the Overview is showing. The rail always has a selection.
  String? _openAreaId;
  Map<String, dynamic>? _openArea;
  bool _saving = false;

  /// weekday -> list of {start, end, max}. Absent weekday = closed.
  final Map<int, List<Map<String, dynamic>>> _grid = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant CapacitySettings old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _areas.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/areas', const {});

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _areas = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _pattern = response.body['pattern'] as Map<String, dynamic>?;
    });
  }

  Future<void> _openArea_(String id) async {
    setState(() {
      _openAreaId = id;
      _openArea = null;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/areas/$id', const {});

    if (!mounted || _openAreaId != id) return;

    setState(() {
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _openArea = response.body['data'] as Map<String, dynamic>?;
      _grid.clear();
      for (final slot in (_openArea?['slots'] as List? ?? const [])
          .cast<Map<String, dynamic>>()) {
        (_grid[slot['weekday'] as int] ??= []).add({
          'start_time': slot['start_time'],
          'end_time': slot['end_time'],
          'max_jobs': slot['max_jobs'],
        });
      }
    });
  }

  Future<void> _saveGrid() async {
    final id = _openAreaId;
    if (id == null) return;

    setState(() => _saving = true);

    // Flattened from the map, so a weekday absent from `_grid` sends no rows
    // at all. That absence IS the closure — there is no zero to write.
    final slots = <Map<String, dynamic>>[
      for (final entry in _grid.entries)
        for (final slot in entry.value)
          {
            'weekday': entry.key,
            'start_time': slot['start_time'],
            'end_time': slot['end_time'],
            'max_jobs': slot['max_jobs'],
          },
    ];

    final response =
        await widget.api.put('/scheduling/areas/$id/slots', {'slots': slots});

    if (!mounted) return;

    setState(() {
      _saving = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
    });

    if (response.ok) {
      await _load();
      if (mounted) await _openArea_(id);
    }
  }

  /// Deletes the day's rows. Never writes zeros — see the class docblock.
  void _closeDay(int weekday) => setState(() => _grid.remove(weekday));

  void _openDay(int weekday) => setState(() {
    _grid[weekday] = [
      for (final slot in _pattern?['slots'] as List? ?? const [])
        if ((slot as Map)['weekday'] == weekday)
          {
            'start_time': slot['start_time'],
            'end_time': slot['end_time'],
            'max_jobs': slot['max_jobs'],
          },
    ];

    // A day the pattern does not cover still has to become something.
    if (_grid[weekday]!.isEmpty) {
      _grid[weekday] = [
        {'start_time': '08:00', 'end_time': '12:00', 'max_jobs': 1},
      ];
    }
  });

  List<Map<String, dynamic>> get _filtered {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return _areas;

    return _areas
        .where((a) => (a['name'] as String? ?? '').toLowerCase().contains(q))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= CapacitySettings.splitAbove;
        final paneOnly = !wide && _openAreaId != null;

        return AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: paneOnly
              ? _pane(showBack: true)
              : wide
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(width: 236, child: _rail()),
                    VerticalDivider(
                      width: 1,
                      thickness: 1,
                      color: EzBiziTheme.edge(context),
                    ),
                    Expanded(child: _pane(showBack: false)),
                  ],
                )
              // Below 600 the rail IS the screen until something is selected,
              // which is the sub-600 rule Web Management already ships.
              : _rail(),
        );
      },
    );
  }

  Widget _rail() {
    final theme = Theme.of(context);
    final areas = _filtered;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            controller: _search,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              isDense: true,
              prefixIcon: Icon(Icons.search, size: 18),
              hintText: 'Search areas',
              border: OutlineInputBorder(),
            ),
          ),
        ),
        // Overview is pinned above the list and never scrolls away.
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: ListTile(
            dense: true,
            selected: _openAreaId == null,
            title: const Text('Overview'),
            subtitle: const Text('Pattern & exceptions'),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(8),
            ),
            onTap: () => setState(() {
              _openAreaId = null;
              _openArea = null;
            }),
          ),
        ),
        const Divider(height: 9),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 6),
          child: Text(
            areas.length == 1 ? '1 service area' : '${areas.length} service areas',
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            itemCount: areas.length,
            itemBuilder: (context, i) {
              final area = areas[i];
              final differs = area['follows_pattern'] == false;

              return ListTile(
                dense: true,
                selected: _openAreaId == area['id'],
                title: Text(area['name'] as String? ?? ''),
                subtitle: Text(
                  '${area['zip_count'] ?? 0} zips${differs ? ' · differs' : ''}',
                ),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
                onTap: () => _openArea_(area['id'] as String),
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _pane({required bool showBack}) =>
      _openAreaId == null ? _overview() : _areaPane(showBack: showBack);

  Widget _overview() {
    final theme = Theme.of(context);
    final differing =
        _areas.where((a) => a['follows_pattern'] == false).toList();
    final slots =
        (_pattern?['slots'] as List? ?? const []).cast<Map<String, dynamic>>();

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 32),
      children: [
        Text('Capacity', style: theme.textTheme.titleMedium),
        const SizedBox(height: 2),
        Text(
          'Most franchises run one pattern everywhere. Set it here, then open '
          'an area on the left to give it different numbers.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 16),
        _card(
          theme,
          title: 'Your slot pattern',
          badge: _pattern == null
              ? 'nothing set up yet'
              : 'followed by ${_pattern!['followed_by']} of ${_pattern!['of']} areas',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (slots.isEmpty)
                Text(
                  'No area has slots yet. Open one and set its week.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                )
              else
                Text(
                  _describePattern(slots),
                  style: theme.textTheme.bodyMedium,
                ),
            ],
          ),
        ),
        _card(
          theme,
          title: 'Areas that differ',
          badge: '${differing.length}',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (differing.isEmpty)
                Text(
                  'Every area follows the pattern.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                )
              else
                for (final area in differing)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(area['name'] as String? ?? ''),
                    trailing: const Icon(Icons.chevron_right, size: 18),
                    onTap: () => _openArea_(area['id'] as String),
                  ),
              const SizedBox(height: 4),
              Text(
                'Listed so an exception cannot be forgotten. An area appears '
                'here only once somebody edits it directly, which is the only '
                'way to differ.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _areaPane({required bool showBack}) {
    final theme = Theme.of(context);
    final area = _openArea;

    if (area == null) {
      return const Center(child: CircularProgressIndicator());
    }

    final rail = _areas.firstWhere(
      (a) => a['id'] == _openAreaId,
      orElse: () => const {},
    );
    final differs = rail['follows_pattern'] == false;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
      children: [
        Row(
          children: [
            if (showBack)
              IconButton(
                icon: const Icon(Icons.arrow_back),
                tooltip: 'Areas',
                onPressed: () => setState(() {
                  _openAreaId = null;
                  _openArea = null;
                }),
              ),
            Expanded(
              child: Text(
                area['name'] as String? ?? '',
                style: theme.textTheme.titleMedium,
              ),
            ),
            Text(
              '${(area['zips'] as List? ?? const []).length} zips',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
        // The exception is shown HERE, not only in the Overview list, because
        // somebody who navigated straight to this area to change a number is
        // exactly the person who has not opened the Overview.
        if (differs)
          Container(
            margin: const EdgeInsets.only(top: 10),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.secondaryContainer,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'This area does not follow the pattern. Applying the pattern to '
              'every area would change it back — and would say so first.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        const SizedBox(height: 14),
        Text(
          'Jobs ${area['name']} can take per slot, per weekday.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 10),
        // One card per day, slots as rows. The seven-column grid is dropped
        // entirely rather than made to scroll sideways.
        for (final (weekday, label) in _week) _dayEditor(theme, weekday, label),
        const SizedBox(height: 16),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: _saving ? null : _saveGrid,
            child: Text(_saving ? 'Saving…' : 'Save ${area['name']}'),
          ),
        ),
      ],
    );
  }

  Widget _dayEditor(ThemeData theme, int weekday, String label) {
    final slots = _grid[weekday];
    final closed = slots == null || slots.isEmpty;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 8, 8, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(label, style: theme.textTheme.titleSmall),
                ),
                if (closed)
                  TextButton(
                    onPressed: () => _openDay(weekday),
                    child: const Text('Open'),
                  )
                else
                  TextButton(
                    onPressed: () => _closeDay(weekday),
                    child: const Text('Close'),
                  ),
              ],
            ),
            if (closed)
              Text(
                'Closed',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              )
            else
              for (var i = 0; i < slots.length; i++)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${slots[i]['start_time']} – ${slots[i]['end_time']}',
                          style: theme.textTheme.bodyMedium,
                        ),
                      ),
                      SizedBox(
                        width: 64,
                        child: TextFormField(
                          key: ValueKey('slot-$weekday-$i-${slots[i]['start_time']}'),
                          initialValue: '${slots[i]['max_jobs']}',
                          textAlign: TextAlign.center,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                            isDense: true,
                            border: OutlineInputBorder(),
                          ),
                          onChanged: (value) {
                            final n = int.tryParse(value);
                            // Zero is not storable and the server refuses it.
                            // Closing a day is the Close button, not a zero.
                            if (n != null && n > 0) slots[i]['max_jobs'] = n;
                          },
                        ),
                      ),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }

  Widget _card(
    ThemeData theme, {
    required String title,
    required String badge,
    required Widget child,
  }) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(title, style: theme.textTheme.titleSmall),
                Chip(
                  label: Text(badge),
                  visualDensity: VisualDensity.compact,
                  padding: EdgeInsets.zero,
                ),
              ],
            ),
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );
  }

  /// "Three windows a day, two jobs each" from the rows themselves.
  String _describePattern(List<Map<String, dynamic>> slots) {
    final days = slots.map((s) => s['weekday']).toSet().length;
    final windows = slots
        .map((s) => '${s['start_time']}–${s['end_time']}')
        .toSet()
        .toList()
      ..sort();
    final jobs = slots.map((s) => s['max_jobs']).toSet();

    final capacity = jobs.length == 1
        ? '${jobs.first} job${jobs.first == 1 ? '' : 's'} each'
        : 'varying capacity';

    return '${windows.join(', ')} — $capacity, across $days '
        '${days == 1 ? 'day' : 'days'}.';
  }
}
