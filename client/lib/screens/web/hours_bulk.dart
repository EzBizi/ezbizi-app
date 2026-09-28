import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/numbered_step.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';

/// Hours on several websites at once: Rodrigo's brief, drawn as design 26
/// (`docs/previews/web-hours-bulk-editor-layouts.html`), Jose's pick 2, the
/// numbered steps; a phone gets the same form stacked (5).
///
/// Nothing is written from the form. Review changes asks the server
/// (`POST /web/hours/review`), which reads every site's live hours and answers
/// per site and per day; Update applies exactly that review by its token
/// (`/web/hours/apply`), and a review over 30 minutes old is refused.
class HoursBulkScreen extends StatefulWidget {
  const HoursBulkScreen({
    super.key,
    required this.api,
    required this.formats,
    this.startWith,
  });

  final ApiClient api;
  final DisplayFormats formats;

  /// The website the editor was opened from, ticked to begin with.
  final String? startWith;

  @override
  State<HoursBulkScreen> createState() => _HoursBulkScreenState();
}

enum _Act { keep, hours, closed }

/// One day of the request. A null edge keeps what the site has.
class _Day {
  _Act act = _Act.keep;
  TimeOfDay? open;
  TimeOfDay? close;

  /// Set hours with both edges on Keep changes nothing, as the server reads it.
  bool get asks =>
      act == _Act.closed ||
      (act == _Act.hours && (open != null || close != null));
}

const _dayNames = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
];

/// ponytail: the US zones, which is every tenant today. Read the settings
/// registry's timezone options instead when a tenant outside the US appears.
const _zones = <(String, String)>[
  ('America/New_York', 'Eastern Time (ET)'),
  ('America/Chicago', 'Central Time (CT)'),
  ('America/Denver', 'Mountain Time (MT)'),
  ('America/Phoenix', 'Arizona (no daylight saving)'),
  ('America/Los_Angeles', 'Pacific Time (PT)'),
  ('America/Anchorage', 'Alaska Time (AKT)'),
  ('Pacific/Honolulu', 'Hawaii Time (HT)'),
];

class _HoursBulkScreenState extends State<HoursBulkScreen> {
  List<Map<String, dynamic>> _sites = const [];
  final Set<String> _picked = {};
  final List<_Day> _week = [for (var i = 0; i < 7; i++) _Day()];
  String? _zone;
  TimeOfDay? _notBefore;

  bool _loading = true;
  bool _busy = false;
  String? _error;
  Map<String, dynamic>? _review;
  Map<String, dynamic>? _result;
  int _tab = 0;
  TimeOfDay? _reviewedAt;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/web/sites');
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _sites = (response.body['data'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      if (_sites.any((s) => s['id'] == widget.startWith)) {
        _picked.add(widget.startWith!);
      }
    });
  }

  int get _daysAsked => _week.where((d) => d.asks).length;

  String _hhmm(TimeOfDay t) =>
      '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

  String _clock(TimeOfDay t) => widget.formats.formatTime(t.hour, t.minute);

  /// "17:00" from the server, drawn the organization's way.
  String _wire(String? hhmm) =>
      hhmm == null ? '' : widget.formats.formatWallClock(hhmm);

  Map<String, dynamic> _edge(TimeOfDay? t) =>
      t == null ? {'action': 'keep'} : {'action': 'set', 'time': _hhmm(t)};

  Map<String, dynamic> get _body => {
    'site_ids': _picked.toList(),
    'destinations': ['website'],
    'source_timezone': _zone,
    'not_before': _notBefore == null ? null : _hhmm(_notBefore!),
    'schedule': {
      for (var i = 0; i < 7; i++)
        if (_week[i].asks)
          _dayNames[i].toLowerCase(): _week[i].act == _Act.closed
              ? {'action': 'closed'}
              : {
                  'action': 'set_hours',
                  'open': _edge(_week[i].open),
                  'close': _edge(_week[i].close),
                },
    },
  };

  Future<void> _askReview() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/web/hours/review', _body);
    if (!mounted) return;

    setState(() {
      _busy = false;
      if (response.ok) {
        _review = response.body['data'] as Map<String, dynamic>?;
        _reviewedAt = TimeOfDay.now();
        _tab = 0;
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _apply() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/web/hours/apply', {
      'token': _review!['token'],
    });
    if (!mounted) return;

    setState(() {
      _busy = false;
      if (response.ok) {
        _result = response.body['data'] as Map<String, dynamic>?;
      } else {
        // 409: expired, or not this person's. The form is kept; review again.
        _error = response.message;
        if (response.statusCode == 409) _review = null;
      }
    });
  }

  List<Map<String, dynamic>> get _places =>
      ((_review?['places'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();

  int get _willChange => _places.where((p) => p['status'] == 'change').length;

  @override
  Widget build(BuildContext context) {
    final title = _result != null
        ? 'Done'
        : _review != null
        ? 'Review'
        : 'Hours on several websites';

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _result != null
          ? _done(context)
          : _review != null
          ? _reviewBody(context)
          : _form(context),
      bottomNavigationBar: _loading ? null : _bar(context),
    );
  }

  // ── the bar at the foot ──────────────────────────────────────────

  Widget _bar(BuildContext context) {
    final theme = Theme.of(context);
    // A phone has the app bar's back arrow, so no Cancel, and the note goes
    // above the buttons rather than being squeezed to "Revi…" beside them.
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final List<Widget> actions;
    final String note;

    if (_result != null) {
      note = '';
      actions = [
        FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Done'),
        ),
      ];
    } else if (_review != null) {
      note = 'Reviewed ${_clock(_reviewedAt!)} · good for 30 minutes';
      actions = [
        TextButton(
          onPressed: _busy ? null : () => setState(() => _review = null),
          child: Text(narrow ? 'Edit' : 'Back to the form'),
        ),
        FilledButton(
          key: const Key('hours-bulk-apply'),
          onPressed: _busy || _willChange == 0 ? null : _apply,
          child: Text(
            _willChange == 1
                ? 'Update 1 website'
                : 'Update $_willChange websites',
          ),
        ),
      ];
    } else {
      final sites = _picked.length;
      note =
          '${sites == 1 ? '1 website' : '$sites websites'} · '
          '${_daysAsked == 1 ? '1 day' : '$_daysAsked days'}';
      actions = [
        if (!narrow)
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
        FilledButton(
          key: const Key('hours-bulk-review'),
          onPressed: _busy || sites == 0 || _daysAsked == 0 ? null : _askReview,
          child: const Text('Review changes'),
        ),
      ];
    }

    final noteText = Text(
      note,
      style: theme.textTheme.bodySmall,
      overflow: TextOverflow.ellipsis,
    );
    final buttons = [
      if (_busy) ...[
        const SizedBox(
          width: 18,
          height: 18,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
        const SizedBox(width: 12),
      ],
      for (final (i, action) in actions.indexed) ...[
        if (i > 0) const SizedBox(width: 8),
        Flexible(child: action),
      ],
    ];

    return Material(
      color: theme.colorScheme.surface,
      child: SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          decoration: BoxDecoration(
            border: Border(
              top: BorderSide(color: theme.colorScheme.outlineVariant),
            ),
          ),
          child: narrow
              ? Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (note.isNotEmpty) ...[
                      noteText,
                      const SizedBox(height: 6),
                    ],
                    Row(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: buttons,
                    ),
                  ],
                )
              : Row(
                  children: [
                    Expanded(child: noteText),
                    ...buttons,
                  ],
                ),
        ),
      ),
    );
  }

  // ── the form ─────────────────────────────────────────────────────

  Widget _page({required List<Widget> children}) => ListView(
    padding: const EdgeInsets.fromLTRB(16, 20, 16, 32),
    children: [
      Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 880),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: children,
          ),
        ),
      ),
    ],
  );

  Widget _form(BuildContext context) {
    final theme = Theme.of(context);

    return _page(
      children: [
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 16)],
        NumberedStep(
          number: 1,
          title: 'Which websites',
          note:
              'Each keeps its own time zone. Google Business Profile is not '
              'switched on yet.',
          child: _sites.isEmpty
              ? Text(
                  'This company has no websites yet. Add one under SEO first.',
                  style: theme.textTheme.bodyMedium,
                )
              : Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final site in _sites)
                      FilterChip(
                        label: Text(site['host'] as String? ?? ''),
                        selected: _picked.contains(site['id']),
                        onSelected: (on) => setState(() {
                          final id = site['id'] as String;
                          on ? _picked.add(id) : _picked.remove(id);
                        }),
                      ),
                  ],
                ),
        ),
        const SizedBox(height: 24),
        NumberedStep(
          number: 2,
          title: 'The week',
          note:
              "Pick a day's action, then its times. A day on Keep existing is not touched.",
          child: LayoutBuilder(
            builder: (context, box) => Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (var i = 0; i < 7; i++)
                  box.maxWidth >= 620
                      ? _dayRow(context, i)
                      : _dayCard(context, i),
              ],
            ),
          ),
        ),
        const SizedBox(height: 24),
        NumberedStep(
          number: 3,
          title: 'Time zone and earliest opening',
          note: 'Both optional.',
          child: _options(context),
        ),
      ],
    );
  }

  /// Keep existing · Set hours · Closed all day — the one control per day
  /// the brief asks for, instead of hours beside an on/off switch.
  Widget _act(int day, {required bool short}) {
    return SegmentedButton<_Act>(
      key: Key('hours-bulk-act-$day'),
      showSelectedIcon: false,
      segments: [
        ButtonSegment(
          value: _Act.keep,
          label: Text(short ? 'Keep' : 'Keep existing'),
        ),
        ButtonSegment(
          value: _Act.hours,
          label: Text(short ? 'Hours' : 'Set hours'),
        ),
        ButtonSegment(
          value: _Act.closed,
          label: Text(short ? 'Closed' : 'Closed all day'),
        ),
      ],
      selected: {_week[day].act},
      onSelectionChanged: (picked) =>
          setState(() => _week[day].act = picked.first),
    );
  }

  Widget _edges(BuildContext context, int day) {
    final d = _week[day];

    return Wrap(
      spacing: 16,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        _time(
          context,
          label: 'Opens',
          key: Key('hours-bulk-open-$day'),
          value: d.open,
          onChanged: (t) => setState(() => d.open = t),
        ),
        _time(
          context,
          label: 'Closes',
          key: Key('hours-bulk-close-$day'),
          value: d.close,
          onChanged: (t) => setState(() => d.close = t),
        ),
      ],
    );
  }

  /// "Keep" or a time; the × puts it back to Keep.
  Widget _time(
    BuildContext context, {
    required String label,
    required Key key,
    required TimeOfDay? value,
    required ValueChanged<TimeOfDay?> onChanged,
  }) {
    final theme = Theme.of(context);

    return Wrap(
      spacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(label, style: theme.textTheme.bodySmall),
        OutlinedButton(
          key: key,
          onPressed: () async {
            final picked = await showTimePicker(
              context: context,
              initialTime: value ?? const TimeOfDay(hour: 9, minute: 0),
            );
            if (picked != null) onChanged(picked);
          },
          child: Text(value == null ? 'Keep' : _clock(value)),
        ),
        if (value != null)
          IconButton(
            tooltip: 'Keep the saved ${label.toLowerCase()} time',
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.close, size: 16),
            onPressed: () => onChanged(null),
          ),
      ],
    );
  }

  Widget _dayRow(BuildContext context, int day) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Wrap(
        spacing: 16,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: 104,
            child: Text(_dayNames[day], style: theme.textTheme.titleSmall),
          ),
          _act(day, short: false),
          if (_week[day].act == _Act.hours) _edges(context, day),
        ],
      ),
    );
  }

  Widget _dayCard(BuildContext context, int day) {
    final theme = Theme.of(context);

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(_dayNames[day], style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          _act(day, short: true),
          if (_week[day].act == _Act.hours) ...[
            const SizedBox(height: 10),
            _edges(context, day),
          ],
        ],
      ),
    );
  }

  Widget _options(BuildContext context) {
    final theme = Theme.of(context);

    return Wrap(
      spacing: 24,
      runSpacing: 16,
      children: [
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 320),
          child: SearchPickerField<String?>(
            key: const Key('hours-bulk-zone'),
            value: _zone,
            label: 'Convert hours from',
            helperText: _zone == null
                ? 'Each website keeps the times as typed, in its own time.'
                : 'The times above are in this zone; each website converts '
                      'them into its own.',
            helperMaxLines: 3,
            options: [
              const SearchOption<String?>(
                value: null,
                label: 'No — each website\'s own time',
              ),
              for (final (id, label) in _zones)
                SearchOption<String?>(value: id, label: label),
            ],
            onChanged: (zone) => setState(() => _zone = zone),
          ),
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            _time(
              context,
              label: "Don't open before",
              key: const Key('hours-bulk-not-before'),
              value: _notBefore,
              onChanged: (t) => setState(() => _notBefore = t),
            ),
            const SizedBox(height: 4),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 300),
              child: Text(
                'In each website\'s own time, after converting. Only the '
                'opening moves, never the closing.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          ],
        ),
      ],
    );
  }

  // ── the review ───────────────────────────────────────────────────

  static (String, Tone) _reviewPill(String? status) => switch (status) {
    'change' => ('Will change', Tone.info),
    'unchanged' => ('Unchanged', Tone.neutral),
    'blocked' => ('Not written', Tone.caution),
    _ => ('Not available', Tone.neutral),
  };

  Widget _reviewBody(BuildContext context) {
    final theme = Theme.of(context);
    final places = _places;
    final wide = MediaQuery.sizeOf(context).width >= 760;
    final summary = (_review?['summary'] as Map?) ?? const {};

    String count(String key, String word) {
      final n = (summary[key] as num?)?.toInt() ?? 0;
      return n == 0 ? '' : '$n $word';
    }

    final line = [
      count('change', 'will change'),
      count('unchanged', 'unchanged'),
      count('blocked', 'not written'),
    ].where((s) => s.isNotEmpty).join(' · ');

    return _page(
      children: [
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 12)],
        Text(line, style: theme.textTheme.titleSmall),
        const SizedBox(height: 12),
        if (wide && places.length > 1) ...[
          TabBarLike(
            labels: [for (final p in places) (_tabLabel(p), Icons.language)],
            selected: _tab.clamp(0, places.length - 1),
            onSelected: (i) => setState(() => _tab = i),
          ),
          const SizedBox(height: 12),
          _place(context, places[_tab.clamp(0, places.length - 1)], wide: true),
        ] else
          for (final place in places) ...[
            _place(context, place, wide: wide),
            const SizedBox(height: 12),
          ],
      ],
    );
  }

  /// "la.everdoors.com · 5", or "· blocked", as drawn.
  String _tabLabel(Map<String, dynamic> place) {
    final host = place['host'] as String? ?? '';
    if (place['status'] == 'blocked') return '$host · blocked';
    final days = ((place['days'] as List?) ?? const [])
        .whereType<Map>()
        .where((d) => d['changed'] == true)
        .length;
    return days == 0 ? host : '$host · $days';
  }

  Widget _place(
    BuildContext context,
    Map<String, dynamic> place, {
    required bool wide,
  }) {
    final theme = Theme.of(context);
    final (label, tone) = _reviewPill(place['status'] as String?);
    final days = ((place['days'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final reasons = ((place['reasons'] as List?) ?? const [])
        .map((r) => '$r')
        .toList();

    return Container(
      key: ValueKey('hours-bulk-place-${place['site_id']}'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(14),
      ),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      place['host'] as String? ?? '',
                      style: theme.textTheme.titleSmall,
                    ),
                    Text(
                      place['timezone'] as String? ?? 'No time zone set',
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              StatusPill(label, tone: tone),
            ],
          ),
          if (reasons.isNotEmpty) ...[
            const SizedBox(height: 8),
            if (place['status'] == 'blocked')
              Text(
                'Nothing is written to this website until these are fixed:',
                style: theme.textTheme.bodySmall,
              ),
            for (final reason in reasons)
              Text(
                reason,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: ToneColours.of(context, Tone.bad).ink,
                ),
              ),
          ],
          if (days.isNotEmpty) ...[
            const SizedBox(height: 10),
            wide ? _dayTable(context, days) : _dayLines(context, days),
          ],
        ],
      ),
    );
  }

  String _span(Map? h) {
    if (h == null) return '—';
    if (h['closed'] == true) return 'Closed';
    return '${_wire(h['open'] as String?)} – ${_wire(h['close'] as String?)}';
  }

  String _asked(Map<String, dynamic> day) =>
      day['action'] == 'closed' ? 'Closed all day' : _pair(day['requested']);

  /// A requested or converted pair, where either edge may be Keep (null).
  String _pair(Object? edges) {
    final open = (edges as Map?)?['open'] as String?;
    final close = edges?['close'] as String?;
    return switch ((open, close)) {
      (null, null) => '—',
      (final o?, null) => 'opens ${_wire(o)}',
      (null, final c?) => 'closes ${_wire(c)}',
      (final o?, final c?) => '${_wire(o)} – ${_wire(c)}',
    };
  }

  String? _noteFor(Map<String, dynamic> day) {
    if (day['error'] != null) return '${day['error']}';
    if (day['adjusted'] == true) {
      return _notBefore == null
          ? 'opening moved'
          : 'not before ${_clock(_notBefore!)}';
    }
    if (day['changed'] != true) return 'no change';
    return null;
  }

  Widget _dayTable(BuildContext context, List<Map<String, dynamic>> days) {
    final theme = Theme.of(context);
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final quiet = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    TableRow row(Map<String, dynamic> day) {
      final changed = day['changed'] == true;
      final note = _noteFor(day);
      final style = changed ? theme.textTheme.bodyMedium : quiet;

      Widget cell(String text, {TextStyle? s}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 6),
        child: Text(text, style: s ?? style),
      );

      return TableRow(
        children: [
          cell('${day['day']}'),
          cell(_span(day['existing'] as Map?)),
          cell(_asked(day)),
          cell(_pair(day['converted'])),
          cell(
            _span(day['final'] as Map?),
            s: changed
                ? theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  )
                : quiet,
          ),
          cell(
            note ?? '',
            s: theme.textTheme.bodySmall?.copyWith(
              color: day['error'] != null
                  ? ToneColours.of(context, Tone.bad).ink
                  : day['adjusted'] == true
                  ? ToneColours.of(context, Tone.caution).ink
                  : theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      );
    }

    return Table(
      columnWidths: const {0: FixedColumnWidth(52), 5: FlexColumnWidth(1.4)},
      defaultVerticalAlignment: TableCellVerticalAlignment.middle,
      children: [
        TableRow(
          children: [
            for (final h in [
              'Day',
              'Now',
              'Requested',
              'Converted',
              'Final',
              '',
            ])
              Padding(
                padding: const EdgeInsets.all(6),
                child: Text(h.toUpperCase(), style: head),
              ),
          ],
        ),
        for (final day in days) row(day),
      ],
    );
  }

  /// On a phone: the days that change, one line each; the rest named once.
  Widget _dayLines(BuildContext context, List<Map<String, dynamic>> days) {
    final theme = Theme.of(context);
    final same = [
      for (final d in days)
        if (d['changed'] != true && d['error'] == null) '${d['day']}',
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final d in days)
          if (d['changed'] == true || d['error'] != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '${d['day']}  ',
                          style: theme.textTheme.titleSmall,
                        ),
                        TextSpan(
                          text: _span(d['existing'] as Map?),
                          style: theme.textTheme.bodySmall?.copyWith(
                            decoration: TextDecoration.lineThrough,
                          ),
                        ),
                        const TextSpan(text: '  →  '),
                        TextSpan(
                          text: d['error'] != null
                              ? 'not written'
                              : _span(d['final'] as Map?),
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (_noteFor(d) case final note?)
                    Text(
                      note,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: d['error'] != null
                            ? ToneColours.of(context, Tone.bad).ink
                            : ToneColours.of(context, Tone.caution).ink,
                      ),
                    ),
                ],
              ),
            ),
        if (same.isNotEmpty)
          Text(
            '${same.join(', ')} unchanged',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );
  }

  // ── the result ───────────────────────────────────────────────────

  static (String, Tone) _resultPill(String? status) => switch (status) {
    'changed' => ('Changed', Tone.ok),
    'unchanged' => ('Unchanged', Tone.neutral),
    'stale' => ('Changed since the review', Tone.caution),
    'unconfirmed' => ('Not confirmed', Tone.caution),
    _ => ('Failed', Tone.bad),
  };

  Widget _done(BuildContext context) {
    final theme = Theme.of(context);
    final results = ((_result?['results'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final applied = {for (final r in results) r['site_id']};

    // The review's other websites, which the apply never touched: say what
    // happened to them too, so the list is every website that was ticked.
    final untouched = [
      for (final p in _places)
        if (!applied.contains(p['site_id'])) p,
    ];

    Widget line(String host, (String, Tone) pill, String? reason) => Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(host, style: theme.textTheme.titleSmall),
                if (reason != null && reason.isNotEmpty)
                  Text(reason, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
          StatusPill(pill.$1, tone: pill.$2),
        ],
      ),
    );

    return _page(
      children: [
        Text(
          'Each website was read back after writing.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 8),
        for (final r in results)
          line(
            r['host'] as String? ?? '',
            _resultPill(r['status'] as String?),
            r['reason'] as String?,
          ),
        for (final p in untouched)
          line(
            p['host'] as String? ?? '',
            _reviewPill(p['status'] as String?),
            ((p['reasons'] as List?) ?? const []).firstOrNull?.toString(),
          ),
      ],
    );
  }
}
