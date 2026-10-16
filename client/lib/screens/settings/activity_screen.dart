import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';

/// Settings › Activity: who did what in this view, newest first.
///
/// Reads `GET /audit-events`, which is behind `audit.view` and scoped to the
/// current view on the server; this end only filters by window and source.
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

    return ListView(
      padding: const EdgeInsets.all(16),
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
            const SizedBox(width: 8),
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
        const SizedBox(height: 16),
        if (_error != null) ErrorNote(_error!),
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
                    title: Text(
                      [
                        _rows[i]['actor'] ?? _sources[_rows[i]['source']] ?? '',
                        _rows[i]['action'],
                        ?_rows[i]['target'],
                      ].where((s) => '$s'.isNotEmpty).join(' · '),
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
