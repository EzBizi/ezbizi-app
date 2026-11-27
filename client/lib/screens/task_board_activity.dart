import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/timestamps.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

/// Monday's board Activity Log (gap review P1, 11-24): every change on the
/// board's tasks, newest first, filtered by time, group, column and person.
/// Tapping a row opens its task through [onOpenTask].
class BoardActivityPanel extends StatefulWidget {
  const BoardActivityPanel({
    super.key,
    required this.api,
    required this.board,
    required this.formats,
    required this.people,
    this.onOpenTask,
  });

  final ApiClient api;
  final Map<String, dynamic> board;
  final DisplayFormats formats;
  final Map<String, String> people;
  final void Function(String taskId)? onOpenTask;

  @override
  State<BoardActivityPanel> createState() => _BoardActivityPanelState();
}

class _BoardActivityPanelState extends State<BoardActivityPanel> {
  List<Map<String, dynamic>>? _rows;
  String? _error;
  int? _days = 7;
  String? _group;
  String? _label;
  String? _person;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Only the newest request's answer shows (audit 11-27: a slow older filter overwrote a newer one).
  int _request = 0;

  Future<void> _load() async {
    final mine = ++_request;
    setState(() => _rows = null);
    final res = await widget.api
        .get('/task-boards/${widget.board['id']}/activity', {
          'days': ?_days?.toString(),
          'group_id': ?_group,
          'label': ?_label,
          'user_id': ?_person,
        });
    if (!mounted || mine != _request) return;
    setState(() {
      _error = res.ok ? null : res.reason;
      _rows = res.ok
          ? [
              for (final r in (res.body['data'] as List).whereType<Map>())
                r.cast<String, dynamic>(),
            ]
          : const [];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final groups = [
      for (final g in (widget.board['groups'] as List? ?? const []))
        if (g is Map) (id: '${g['id']}', name: '${g['name']}'),
    ];
    final labels = {
      'Status',
      'Owner',
      'Due',
      'Title',
      'Group',
      for (final c in (widget.board['columns'] as List? ?? const []))
        if (c is Map) '${c['name']}',
    }.toList();
    Widget filter<T>(
      String key,
      String hint,
      T? value,
      List<SearchOption<T>> options,
      void Function(T?) set,
    ) => SizedBox(
      width: 170,
      child: SearchPickerField<T>(
        key: Key('activity-filter-$key'),
        value: value,
        isDense: true,
        hint: hint,
        options: [
          SearchOption<T>(value: null, label: hint),
          ...options,
        ],
        onChanged: (v) {
          setState(() => set(v));
          _load();
        },
      ),
    );

    return SidePanel(
      title: 'Activity Log · ${widget.board['name']}',
      subtitle: 'Every change on this board, newest first.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              filter<int>('days', 'Any time', _days, const [
                SearchOption(value: 1, label: 'Last 24 hours'),
                SearchOption(value: 7, label: 'Last 7 days'),
                SearchOption(value: 30, label: 'Last 30 days'),
                SearchOption(value: 365, label: 'Last year'),
              ], (v) => _days = v),
              filter<String>('group', 'Every group', _group, [
                for (final g in groups)
                  SearchOption(value: g.id, label: g.name),
              ], (v) => _group = v),
              filter<String>('column', 'Every column', _label, [
                for (final l in labels) SearchOption(value: l, label: l),
              ], (v) => _label = v),
              filter<String>('person', 'Everyone', _person, [
                for (final p in widget.people.entries)
                  SearchOption(value: p.key, label: p.value),
              ], (v) => _person = v),
            ],
          ),
          const SizedBox(height: 12),
          if (_error != null)
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          if (_rows == null)
            const LinearProgressIndicator()
          else if (_rows!.isEmpty)
            Text('No changes in this view.', style: muted)
          else
            for (final a in _rows!)
              InkWell(
                key: ValueKey('board-activity-${a['id']}'),
                onTap: widget.onOpenTask == null
                    ? null
                    : () => widget.onOpenTask!('${a['task_id']}'),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '${a['who'] ?? 'An automation'}  ',
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        TextSpan(text: '${a['task']} · '),
                        TextSpan(
                          text:
                              '${a['label']}: ${a['from'] ?? '—'} → ${a['to'] ?? '—'}  ',
                        ),
                        TextSpan(
                          text: formatStamp(
                            a['at'],
                            '',
                            formats: widget.formats,
                          ),
                          style: muted,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
        ],
      ),
    );
  }
}
