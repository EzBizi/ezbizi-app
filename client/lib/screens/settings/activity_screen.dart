import '../../widgets/list_search.dart';
import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

/// Settings › Activity: who did what in this view, newest first.
///
/// Reads `GET /audit-events`, which is behind `audit.view` and scoped to the
/// current view on the server; this end only filters by window and source.
/// "Dana Reyes created customer · Pat Lin" from `customer.created`.
///
/// The actor is the stored label without its address; an automation reads as
/// one. The action code `noun.verb_phrase` turns into words with the verb
/// first, so `contract.version_published` reads "published contract version".
String activitySentence(Map<String, dynamic> row, String? sourceWord) {
  final actor = activityActor(row, sourceWord);
  final target = (row['target'] as String? ?? '').trim();
  final what = activityAction(row['action'] as String? ?? '');

  return [
    [actor, what].where((s) => s.isNotEmpty).join(' '),
    if (target.isNotEmpty) target,
  ].join(' · ');
}

/// Who did it: the stored label without its address; an automation reads as
/// one.
String activityActor(Map<String, dynamic> row, String? sourceWord) {
  final label = (row['actor'] as String? ?? '').trim();
  return label.isEmpty || label == 'system'
      ? (row['source'] == 'system' ? 'An automation' : (sourceWord ?? ''))
      : label.replaceFirst(RegExp(r'\s*<[^>]*>$'), '');
}

/// The module an action belongs to: the code's first part.
String activityModule(Map<String, dynamic> row) =>
    (row['action'] as String? ?? '').split('.').first;

/// `time_clock` → "Time clock".
String activityModuleLabel(String module) {
  final text = module.replaceAll('_', ' ').trim();
  return text.isEmpty ? '' : text[0].toUpperCase() + text.substring(1);
}

/// `lead.recording_played` → "played lead recording".
String activityAction(String code) {
  final parts = code.split('.').where((p) => p.isNotEmpty).toList();
  if (parts.isEmpty) return '';
  final words = [
    for (final part in parts) ...part.split('_').where((w) => w.isNotEmpty),
  ];
  // ponytail: verb-last heuristic; a code that reads badly gets a map entry.
  const irregular = {'run': 'ran', 'sent': 'sent', 'gone': 'removed'};
  final last = words.last;
  final verb =
      irregular[last] ?? (last.endsWith('ed') || last == 'made' ? last : null);
  if (verb == null) return words.join(' ');
  return [verb, ...words.take(words.length - 1)].join(' ');
}

class ActivityScreen extends StatefulWidget {
  const ActivityScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ActivityScreen> createState() => _ActivityScreenState();
}

class _ActivityScreenState extends State<ActivityScreen> {
  static const _windows = {
    '24h': 'Last 24 hours',
    '7d': 'Last 7 days',
    '30d': 'Last 30 days',
    '90d': 'Last 90 days',
  };

  static const _sources = {
    null: 'Everyone',
    'user': 'People',
    'system': 'Automations',
    'staff': 'EzBizi support',
  };

  String _window = '7d';

  /// Who: a source (`user`, `system`, `staff`) or one person (`actor:<id>`).
  String? _who;
  String? _module;
  final _search = TextEditingController();
  Timer? _typing;

  /// The people the server says acted in this window, for the Who picker.
  List<Map<String, dynamic>> _actors = const [];

  /// Every module seen, so the picker keeps a choice its rows no longer show.
  final _modules = <String>{};

  List<Map<String, dynamic>> _rows = const [];
  bool _more = false;
  bool _loading = true;
  String? _error;

  /// The row open beside the table on a wide screen.
  String? _open;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _typing?.cancel();
    _search.dispose();
    super.dispose();
  }

  String? get _source =>
      _who == null || _who!.startsWith('actor:') ? null : _who;
  String? get _actorId =>
      _who != null && _who!.startsWith('actor:') ? _who!.substring(6) : null;

  Future<void> _load({bool append = false}) async {
    setState(() => _loading = true);
    final q = _search.text.trim();

    final response = await widget.api.get('/audit-events', {
      'window': _window,
      'source': ?_source,
      'actor_id': ?_actorId,
      'module': ?_module,
      if (q.isNotEmpty) 'q': q,
      if (append && _rows.isNotEmpty)
        'before': _rows.last['occurred_at'] as String,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _error = null;
      final page = ((response.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      _rows = append ? [..._rows, ...page] : page;
      final meta = response.body['meta'] as Map?;
      _more = meta?['more'] == true;
      _actors = ((meta?['actors'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      _modules.addAll(_rows.map(activityModule).where((m) => m.isNotEmpty));
      if (!append) _open = null;
    });
  }

  void _typed(String _) {
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 400), _load);
  }

  @override
  Widget build(BuildContext context) {
    final formats = widget.session.me!.display;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The same header as every Settings tab (UI queue 110).
        const PanelHeader(note: 'Who changed what in this view, newest first.'),
        Expanded(
          // UI queue 111: a table by day with what changed beside it; a
          // narrow screen lists rows and opens one in a side panel.
          child: LayoutBuilder(
            builder: (context, box) {
              final wide = box.maxWidth >= 900;
              final open = wide
                  ? _rows.where((r) => '${r['id']}' == _open).firstOrNull
                  : null;
              return Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: _list(context, formats, wide)),
                  if (open != null)
                    SizedBox(
                      width: 340,
                      child: Card(
                        margin: const EdgeInsets.fromLTRB(0, 0, 24, 24),
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      'What changed',
                                      style: Theme.of(
                                        context,
                                      ).textTheme.titleMedium,
                                    ),
                                  ),
                                  IconButton(
                                    key: const ValueKey('activity-close'),
                                    tooltip: 'Close',
                                    icon: const Icon(Icons.close),
                                    onPressed: () =>
                                        setState(() => _open = null),
                                  ),
                                ],
                              ),
                              Expanded(
                                child: SingleChildScrollView(
                                  child: ActivityDetail(
                                    key: ValueKey('detail:${open['id']}'),
                                    api: widget.api,
                                    row: open,
                                    sourceWord: _sources[open['source']],
                                    formats: formats,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _filters(BuildContext context) {
    final people = {
      for (final a in _actors)
        if (a['id'] != null) 'actor:${a['id']}': '${a['label'] ?? ''}',
    };
    final modules = {..._modules, ?_module}.toList()..sort();

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 170,
          child: SearchPickerField<String>(
            key: const ValueKey('activity-window'),
            label: 'When',
            isDense: true,
            value: _window,
            options: [
              for (final MapEntry(:key, :value) in _windows.entries)
                SearchOption(value: key, label: value),
            ],
            onChanged: (value) {
              _window = value ?? '7d';
              _load();
            },
          ),
        ),
        SizedBox(
          width: 200,
          child: SearchPickerField<String?>(
            key: const ValueKey('activity-who'),
            label: 'Who',
            isDense: true,
            value: _who,
            options: [
              for (final MapEntry(:key, :value) in _sources.entries)
                SearchOption(value: key, label: value),
              for (final MapEntry(:key, :value) in people.entries)
                SearchOption(value: key, label: value),
            ],
            onChanged: (value) {
              _who = value;
              _load();
            },
          ),
        ),
        SizedBox(
          width: 190,
          child: SearchPickerField<String?>(
            key: const ValueKey('activity-module'),
            label: 'Module',
            isDense: true,
            value: _module,
            options: [
              const SearchOption(value: null, label: 'Any module'),
              for (final m in modules)
                SearchOption(value: m, label: activityModuleLabel(m)),
            ],
            onChanged: (value) {
              _module = value;
              _load();
            },
          ),
        ),
        SizedBox(
          width: 280,
          child: ListSearch(
            key: const ValueKey('activity-search'),
            controller: _search,
            hint: 'A customer, lead or setting',
            onChanged: _typed,
          ),
        ),
      ],
    );
  }

  /// Today, Yesterday, or the date, for a day heading.
  String _day(String? stamp, DisplayFormats formats) {
    final at = DateTime.tryParse(stamp ?? '')?.toLocal();
    if (at == null) return '';
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final day = DateTime(at.year, at.month, at.day);
    if (day == today) return 'Today';
    if (day == today.subtract(const Duration(days: 1))) return 'Yesterday';
    return formats.formatDate(at);
  }

  String _time(String? stamp, DisplayFormats formats) {
    final at = DateTime.tryParse(stamp ?? '')?.toLocal();
    return at == null ? '' : formats.formatTime(at.hour, at.minute);
  }

  void _openRow(
    BuildContext context,
    Map<String, dynamic> row,
    bool wide,
    DisplayFormats formats,
  ) {
    if (wide) {
      setState(() => _open = '${row['id']}');
      return;
    }
    showSidePanel<void>(
      context,
      builder: (_) => SidePanel(
        title: 'What changed',
        child: ActivityDetail(
          api: widget.api,
          row: row,
          sourceWord: _sources[row['source']],
          formats: formats,
        ),
      ),
    );
  }

  Widget _list(BuildContext context, DisplayFormats formats, bool wide) {
    final theme = Theme.of(context);
    final head = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w800,
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget line(List<Widget> cells) => Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 64, child: cells[0]),
        SizedBox(width: 190, child: cells[1]),
        Expanded(child: cells[2]),
        const SizedBox(width: 8),
        SizedBox(width: 150, child: cells[3]),
        SizedBox(width: 120, child: cells[4]),
      ],
    );

    final body = <Widget>[];
    String? day;
    for (final row in _rows) {
      final heading = _day(row['occurred_at'] as String?, formats);
      if (heading != day) {
        day = heading;
        body.add(
          Container(
            color: theme.colorScheme.surfaceContainerHighest,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            child: Text(heading.toUpperCase(), style: head),
          ),
        );
      }
      final who = activityActor(row, _sources[row['source']]);
      final what = activitySentence(row, _sources[row['source']]);
      final rest = what.startsWith(who)
          ? what.substring(who.length).trim()
          : what;
      final selected = '${row['id']}' == _open;
      body.add(
        InkWell(
          key: ValueKey('activity-row:${row['id']}'),
          onTap: () => _openRow(context, row, wide, formats),
          child: Container(
            color: selected ? theme.colorScheme.primaryContainer : null,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            child: wide
                ? line([
                    Text(
                      _time(row['occurred_at'] as String?, formats),
                      style: theme.textTheme.bodySmall,
                    ),
                    Text(
                      who,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    // A sentence, never the server's code (UI queue 110).
                    Text(rest),
                    Text(
                      '${row['where'] ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                    Text(
                      activityModuleLabel(activityModule(row)),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ])
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(what),
                      const SizedBox(height: 2),
                      Text(
                        [
                          _time(row['occurred_at'] as String?, formats),
                          if ('${row['where'] ?? ''}'.isNotEmpty) row['where'],
                          activityModuleLabel(activityModule(row)),
                        ].where((s) => '$s'.isNotEmpty).join(' · '),
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
          ),
        ),
      );
      body.add(const Divider(height: 1));
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      children: [
        _filters(context),
        const SizedBox(height: 16),
        if (_error != null) ...[
          ErrorNote(_error!),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: const ValueKey('activity-retry'),
              onPressed: _load,
              child: const Text('Try again'),
            ),
          ),
        ],
        if (!_loading && _error == null && _rows.isEmpty)
          EmptyState(
            icon: Icons.history,
            title: 'Nothing in this window',
            message:
                'Nobody changed anything here in the '
                '${_windows[_window]!.toLowerCase()}. Try a longer window.',
          ),
        if (_rows.isNotEmpty)
          Card(
            clipBehavior: Clip.antiAlias,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (wide)
                  Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 8,
                    ),
                    child: line([
                      Text('WHEN', style: head),
                      Text('WHO', style: head),
                      Text('WHAT', style: head),
                      Text('WHERE', style: head),
                      Text('MODULE', style: head),
                    ]),
                  ),
                ...body,
              ],
            ),
          ),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(16),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_more)
          Center(
            child: TextButton(
              key: const ValueKey('activity-more'),
              onPressed: () => _load(append: true),
              child: const Text('Show older'),
            ),
          ),
      ],
    );
  }
}

/// One Activity row opened: `GET /audit-events/{id}`, and its `changes` as
/// before → after per field. A shape the logger wrote differently is drawn
/// as plain name and value rather than dropped.
class ActivityDetail extends StatefulWidget {
  const ActivityDetail({
    super.key,
    required this.api,
    required this.row,
    required this.sourceWord,
    required this.formats,
  });

  final ApiClient api;
  final Map<String, dynamic> row;
  final String? sourceWord;
  final DisplayFormats formats;

  @override
  State<ActivityDetail> createState() => _ActivityDetailState();
}

class _ActivityDetailState extends State<ActivityDetail> {
  Map<String, dynamic>? _event;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/audit-events/${widget.row['id']}');
    if (!mounted) return;
    setState(() {
      _error = response.ok ? null : response.reason;
      _event = response.ok
          ? response.body['data'] as Map<String, dynamic>?
          : null;
    });
  }

  static String _words(String key) {
    final text = key.replaceAll('_', ' ').trim();
    return text.isEmpty ? key : text[0].toUpperCase() + text.substring(1);
  }

  static String _value(Object? value) => switch (value) {
    null => '(empty)',
    String v when v.isEmpty => '(empty)',
    String v => v,
    num v => '$v',
    bool v => v ? 'Yes' : 'No',
    _ => jsonEncode(value),
  };

  List<Widget> _pairs(ThemeData theme, Object? data, {required bool changes}) {
    if (data is! Map || data.isEmpty) return const [];
    return [
      for (final MapEntry(:key, :value) in data.entries)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(_words('$key'), style: theme.textTheme.labelLarge),
              const SizedBox(height: 2),
              if (changes &&
                  value is Map &&
                  (value.containsKey('before') || value.containsKey('after')))
                Text(
                  '${_value(value['before'])}  →  ${_value(value['after'])}',
                  key: ValueKey('change:$key'),
                )
              else
                Text(_value(value), key: ValueKey('value:$key')),
            ],
          ),
        ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_error != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ErrorNote(_error!),
          TextButton(onPressed: _load, child: const Text('Try again')),
        ],
      );
    }
    final event = _event;
    if (event == null) {
      return const Center(child: CircularProgressIndicator());
    }
    final changes = _pairs(theme, event['changes'], changes: true);
    final details = _pairs(theme, event['metadata'], changes: false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          activitySentence(event, widget.sourceWord),
          style: theme.textTheme.titleSmall,
        ),
        const SizedBox(height: 4),
        Text(
          formatStamp(event['occurred_at'], '', formats: widget.formats),
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        if (changes.isEmpty && details.isEmpty)
          Text(
            'Nothing more was recorded for this one.',
            style: theme.textTheme.bodyMedium,
          ),
        ...changes,
        if (details.isNotEmpty) ...[
          if (changes.isNotEmpty) const Divider(height: 24),
          Text('Details', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          ...details,
        ],
      ],
    );
  }
}
