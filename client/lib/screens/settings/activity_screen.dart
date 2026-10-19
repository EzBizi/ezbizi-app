import 'dart:convert';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
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
  final label = (row['actor'] as String? ?? '').trim();
  final actor = label.isEmpty || label == 'system'
      ? (row['source'] == 'system' ? 'An automation' : (sourceWord ?? ''))
      : label.replaceFirst(RegExp(r'\s*<[^>]*>$'), '');
  final target = (row['target'] as String? ?? '').trim();
  final what = activityAction(row['action'] as String? ?? '');

  return [
    [actor, what].where((s) => s.isNotEmpty).join(' '),
    if (target.isNotEmpty) target,
  ].join(' · ');
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
  String? _source;
  List<Map<String, dynamic>> _rows = const [];
  bool _more = false;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool append = false}) async {
    setState(() => _loading = true);

    final response = await widget.api.get('/audit-events', {
      'window': _window,
      'source': ?_source,
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
      _more = (response.body['meta'] as Map?)?['more'] == true;
    });
  }

  @override
  Widget build(BuildContext context) {
    final formats = widget.session.me!.display;
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The same header as every Settings tab (UI queue 110).
        const PanelHeader(note: 'Who changed what in this view, newest first.'),
        Expanded(child: _list(context, formats, theme)),
      ],
    );
  }

  Widget _list(BuildContext context, DisplayFormats formats, ThemeData theme) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      children: [
        // Two groups with room between them, so they read as two filters
        // (UI queue 110): when, then who.
        Wrap(
          spacing: 24,
          runSpacing: 8,
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final MapEntry(:key, :value) in _windows.entries)
                  ChoiceChip(
                    key: ValueKey('window:$key'),
                    label: Text(value),
                    selected: _window == key,
                    onSelected: (_) {
                      _window = key;
                      _load();
                    },
                  ),
              ],
            ),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final MapEntry(:key, :value) in _sources.entries)
                  ChoiceChip(
                    key: ValueKey('source:${key ?? 'all'}'),
                    label: Text(value),
                    selected: _source == key,
                    onSelected: (_) {
                      _source = key;
                      _load();
                    },
                  ),
              ],
            ),
          ],
        ),
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
            child: Column(
              children: [
                for (var i = 0; i < _rows.length; i++) ...[
                  if (i > 0) const Divider(height: 1),
                  ListTile(
                    key: ValueKey('activity-row:${_rows[i]['id']}'),
                    // Opens what changed, before and after (UI queue 110).
                    onTap: () => showSidePanel<void>(
                      context,
                      builder: (_) => SidePanel(
                        title: 'What changed',
                        child: ActivityDetail(
                          api: widget.api,
                          row: _rows[i],
                          sourceWord: _sources[_rows[i]['source']],
                          formats: formats,
                        ),
                      ),
                    ),
                    // A sentence, never the server's code (UI queue 110).
                    title: Text(
                      activitySentence(_rows[i], _sources[_rows[i]['source']]),
                    ),
                    trailing: Text(
                      formatStamp(
                        _rows[i]['occurred_at'],
                        '',
                        formats: formats,
                      ),
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                ],
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
