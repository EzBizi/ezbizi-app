import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';

/// Field › Recordings (UI queue 102, design 4: a table and a side panel, in
/// place on a phone). The panel is design 177 option 1 / phone 4 (Jose,
/// 2026-11-03): the summary in labelled sections, then the transcript. The
/// audio is deleted once it is words, so there is no player.
///
/// The server decides whose: your own, or with field.listen everyone Field
/// shows you. Each recording is one-minute pieces played one after another.
/// Kept recordings outlive the 90 days; keeping needs field.manage and a
/// reason.
class FieldRecordingsTab extends StatefulWidget {
  const FieldRecordingsTab({
    super.key,
    required this.api,
    required this.canListen,
    required this.canKeep,
  });

  final ApiClient api;

  /// field.listen: the person filter is offered.
  final bool canListen;

  /// field.manage: Keep and Stop keeping.
  final bool canKeep;

  static const double sideAbove = 900;

  @override
  State<FieldRecordingsTab> createState() => _FieldRecordingsTabState();
}

class _FieldRecordingsTabState extends State<FieldRecordingsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  String? _person;
  DateTime? _day;

  /// What is typed in the search box, sent as `q` 400 ms after the last key.
  String _query = '';
  Timer? _searchDebounce;

  Map<String, dynamic>? _open;

  /// GET field/recordings/{id}/transcript for the open row.
  Map<String, dynamic>? _transcript;
  bool _keeping = false;
  final _reason = TextEditingController();
  String? _panelError;

  @override
  void initState() {
    super.initState();
    _load();
    // A recording finished, transcribed or flagged elsewhere: the list
    // re-reads quietly; the open one and a flag being written are kept.
    _live = LiveChanges.of(widget.api).subscribe({'field'}, (_) {
      if (!_loading && !_keeping) return _load(quiet: true);
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _searchDebounce?.cancel();
    _reason.dispose();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps the rows on screen.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = _rows.isEmpty;
        _error = null;
      });
    }
    final response = await widget.api.get('/field/recordings', {
      'user_id': ?_person,
      if (_day != null) 'date': formatCalendarDay(_day!),
      if (_query.trim().isNotEmpty) 'q': _query.trim(),
    });
    if (!mounted || (quiet && !response.ok)) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _rows = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      final open = _open;
      if (open != null) {
        final fresh = _rows.where((r) => r['id'] == open['id']);
        if (fresh.isNotEmpty) _open = fresh.first;
      }
    });
  }

  // ----------------------------------------------------------- transcript

  void _openRow(Map<String, dynamic> row) {
    setState(() {
      _open = row;
      _transcript = null;
      _keeping = false;
      _panelError = null;
    });
    _loadTranscript(row['id'] as String);
  }

  Future<void> _loadTranscript(String id) async {
    final response = await widget.api.get('/field/recordings/$id/transcript');
    if (!mounted || _open?['id'] != id) return;
    setState(() {
      if (response.ok) {
        _transcript = response.data as Map<String, dynamic>?;
      } else {
        _panelError = response.message;
      }
    });
  }

  Future<void> _flag(bool keep) async {
    final id = _open?['id'];
    final response = await widget.api.post('/field/recordings/$id/flag', {
      'flagged': keep,
      if (keep) 'reason': _reason.text.trim(),
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _panelError = response.message);
      return;
    }
    _reason.clear();
    setState(() => _keeping = false);
    await _load();
  }

  // ----------------------------------------------------------- drawing

  static String _clock(Duration d) {
    final h = d.inHours;
    final m = d.inMinutes.remainder(60);
    if (h > 0) return '$h h ${'$m'.padLeft(2, '0')} min';
    if (d.inMinutes > 0) return '${d.inMinutes} min';
    return '${d.inSeconds} s';
  }

  String _time(String? iso) {
    final at = DateTime.tryParse(iso ?? '')?.toLocal();
    if (at == null) return '';
    final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
    return '${at.month}/${at.day} $h:${'${at.minute}'.padLeft(2, '0')} '
        '${at.hour < 12 ? 'AM' : 'PM'}';
  }

  String _gaps(Map<String, dynamic> row) => [
    for (final e in (row['events'] as List? ?? const []).whereType<Map>())
      '${'${e['type']}'.replaceAll('_', ' ')} ${_time(e['at'] as String?).split(' ').skip(1).join(' ')}',
  ].join(' · ');

  Widget _kept(Map<String, dynamic> row) => row['flagged'] == true
      ? StatusPill(
          'Kept${row['flag_reason'] == null ? '' : ': ${row['flag_reason']}'}',
          tone: Tone.info,
        )
      : StatusPill('Deletes ${row['delete_on'] ?? ''}', tone: Tone.neutral);

  List<({String id, String name})> get _people {
    final seen = <String, String>{};
    for (final r in _rows) {
      final u = r['user'] as Map?;
      if (u?['id'] != null) seen['${u!['id']}'] = '${u['name'] ?? ''}';
    }
    return [for (final e in seen.entries) (id: e.key, name: e.value)];
  }

  void _search(String value) {
    setState(() => _query = value);
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 400), _load);
  }

  /// The loaded rows, narrowed by the search box as well.
  ///
  /// FieldRecordingsController@index (GET /field/recordings) does not read
  /// `q` yet, so the server returns the same newest 200 whatever is typed;
  /// this narrows them until it does. Once the server honours `q` it is a
  /// no-op.
  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _rows;
    return [
      for (final r in _rows)
        if ([
          (r['user'] as Map?)?['name'],
          if (r['job'] is Map) 'JOB-${(r['job'] as Map)['number']}',
          (r['job'] as Map?)?['title'],
          r['flag_reason'],
        ].any((v) => v != null && '$v'.toLowerCase().contains(q)))
          r,
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return LayoutBuilder(
      builder: (context, box) {
        final side = box.maxWidth >= FieldRecordingsTab.sideAbove;
        final open = _open;
        if (!side && open != null) return _panel(theme, open, inPlace: true);

        final list = Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              child: Wrap(
                spacing: 12,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  if (widget.canListen)
                    SizedBox(
                      width: 180,
                      child: SearchPickerField<String?>(
                        key: const ValueKey('field-recordings-person'),
                        value: _person,
                        isDense: true,
                        border: InputBorder.none,
                        options: [
                          const SearchOption(value: null, label: 'Everyone'),
                          for (final p in _people)
                            SearchOption(value: p.id, label: p.name),
                        ],
                        onChanged: (v) {
                          setState(() => _person = v);
                          _load();
                        },
                      ),
                    ),
                  OutlinedButton.icon(
                    key: const ValueKey('field-recordings-day'),
                    icon: const Icon(Icons.event_outlined, size: 18),
                    label: Text(
                      _day == null ? 'Any day' : formatCalendarDay(_day!),
                    ),
                    onPressed: () async {
                      final now = DateTime.now();
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: _day ?? now,
                        firstDate: now.subtract(const Duration(days: 400)),
                        lastDate: now,
                      );
                      if (picked == null) return;
                      setState(() => _day = picked);
                      _load();
                    },
                  ),
                  if (_day != null)
                    TextButton(
                      onPressed: () {
                        setState(() => _day = null);
                        _load();
                      },
                      child: const Text('Any day'),
                    ),
                  // ListSearch's own width: 280 on a desk, the row on a
                  // phone (mobile review 2026-11-13: it was 125 px, cut).
                  ListSearch(
                    key: const ValueKey('field-recordings-search'),
                    hint: 'Search person, job or reason',
                    onChanged: _search,
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _query.trim().isNotEmpty && _shown.isEmpty
                    ? Center(
                        child: Text(
                          'Nothing matches "${_query.trim()}".',
                          style: theme.textTheme.bodyMedium,
                        ),
                      )
                    : _rows.isEmpty
                    ? Center(
                        key: const ValueKey('field-recordings-empty'),
                        child: Text(
                          'No recordings for these filters.',
                          style: theme.textTheme.bodyMedium,
                        ),
                      )
                    : _table(theme, narrow: box.maxWidth < 700),
              ),
            ),
          ],
        );

        if (!side || open == null) return list;
        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: list),
            const VerticalDivider(width: 1),
            SizedBox(width: 340, child: _panel(theme, open, inPlace: false)),
          ],
        );
      },
    );
  }

  Widget _table(ThemeData theme, {required bool narrow}) {
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w800,
    );
    final small = theme.textTheme.bodySmall;
    Widget cell(int flex, Widget child) => Expanded(
      flex: flex,
      child: Padding(padding: const EdgeInsets.only(right: 10), child: child),
    );

    final rows = _shown;
    return ListView.separated(
      key: const ValueKey('tour-field-recordings'),
      itemCount: rows.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, i) {
        if (i == 0) {
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Row(
              children: [
                cell(3, Text('PERSON', style: head)),
                cell(2, Text('JOB', style: head)),
                cell(2, Text('STARTED', style: head)),
                cell(2, Text('LENGTH', style: head)),
                if (!narrow) cell(3, Text('GAPS', style: head)),
                if (!narrow) cell(3, Text('KEPT UNTIL', style: head)),
              ],
            ),
          );
        }
        final r = rows[i - 1];
        final job = r['job'] as Map?;
        return InkWell(
          key: ValueKey('field-recording-${r['id']}'),
          onTap: () => _openRow(r),
          child: Container(
            color: _open?['id'] == r['id']
                ? theme.colorScheme.primaryContainer
                : null,
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
            child: Row(
              children: [
                cell(
                  3,
                  Text(
                    '${(r['user'] as Map?)?['name'] ?? ''}',
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                cell(
                  2,
                  Text(
                    job == null ? '—' : 'JOB-${job['number'] ?? job['id']}',
                    style: small,
                  ),
                ),
                cell(2, Text(_time(r['started_at'] as String?), style: small)),
                cell(
                  2,
                  Text(
                    _clock(
                      Duration(
                        seconds: (r['duration_s'] as num?)?.toInt() ?? 0,
                      ),
                    ),
                    style: small,
                  ),
                ),
                if (!narrow)
                  cell(
                    3,
                    Text(_gaps(r).isEmpty ? '—' : _gaps(r), style: small),
                  ),
                if (!narrow)
                  cell(
                    3,
                    Align(alignment: Alignment.centerLeft, child: _kept(r)),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _panel(
    ThemeData theme,
    Map<String, dynamic> r, {
    required bool inPlace,
  }) {
    final small = theme.textTheme.bodySmall;
    final job = r['job'] as Map?;
    final chunks = (r['chunks'] as num?)?.toInt() ?? 0;
    final total = Duration(seconds: (r['duration_s'] as num?)?.toInt() ?? 0);
    final reason = switch (r['stop_reason']) {
      'done' => 'Done',
      'customer_request' => 'The customer asked',
      'personal' => 'Personal',
      final String other => other.replaceAll('_', ' '),
      _ => null,
    };

    return Material(
      key: const ValueKey('field-recording-panel'),
      color: theme.colorScheme.surface,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Row(
            children: [
              IconButton(
                tooltip: inPlace ? 'Back to the recordings' : 'Close',
                icon: Icon(inPlace ? Icons.arrow_back : Icons.close),
                onPressed: () => setState(() => _open = null),
              ),
              Expanded(
                child: Text(
                  [
                    '${(r['user'] as Map?)?['name'] ?? ''}',
                    if (job != null) 'JOB-${job['number'] ?? job['id']}',
                  ].join(' · '),
                  style: theme.textTheme.titleMedium,
                ),
              ),
            ],
          ),
          Text(
            [
              _time(r['started_at'] as String?),
              _clock(total),
              if (reason != null) 'stopped: $reason',
              if (r['customer_told'] == true) 'customer told',
            ].join(' · '),
            style: small,
          ),
          const SizedBox(height: 12),
          ..._words(theme, chunks),
          if (_gaps(r).isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text('Not recorded: ${_gaps(r)}', style: small),
            ),
          if (_panelError != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                _panelError!,
                style: small?.copyWith(color: theme.colorScheme.error),
              ),
            ),
          const SizedBox(height: 12),
          Align(alignment: Alignment.centerLeft, child: _kept(r)),
          if (widget.canKeep) ...[
            const SizedBox(height: 8),
            if (r['flagged'] == true)
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton(
                  key: const ValueKey('field-recording-unkeep'),
                  onPressed: () => _flag(false),
                  child: const Text('Stop keeping'),
                ),
              )
            else if (!_keeping)
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton(
                  key: const ValueKey('field-recording-keep'),
                  onPressed: () => setState(() => _keeping = true),
                  child: const Text('Keep it…'),
                ),
              )
            else ...[
              TextField(
                key: const ValueKey('field-recording-reason'),
                controller: _reason,
                decoration: const InputDecoration(
                  isDense: true,
                  labelText: 'Why keep it',
                ),
                onChanged: (_) => setState(() {}),
              ),
              Row(
                children: [
                  TextButton(
                    onPressed: () => setState(() => _keeping = false),
                    child: const Text('Cancel'),
                  ),
                  const Spacer(),
                  FilledButton(
                    key: const ValueKey('field-recording-keep-save'),
                    onPressed: _reason.text.trim().isEmpty
                        ? null
                        : () => _flag(true),
                    child: const Text('Keep'),
                  ),
                ],
              ),
            ],
          ],
        ],
      ),
    );
  }

  static const _sections = [
    ('summary', 'Summary'),
    ('done', 'Done'),
    ('found', 'Found'),
    ('recommended', 'Recommended'),
    ('customer_asked', 'Customer asked'),
  ];

  List<Widget> _words(ThemeData theme, int chunks) {
    final small = theme.textTheme.bodySmall;
    final t = _transcript;
    if (t == null) {
      return [
        if (_panelError == null)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 12),
            child: LinearProgressIndicator(),
          ),
      ];
    }
    final summary = (t['summary'] as Map?) ?? const {};
    final segments = ((t['segments'] as List?) ?? const []).cast<Map>();
    final pending = (t['pending'] as num?)?.toInt() ?? 0;

    Widget heading(String label, String copy) => Row(
      children: [
        Expanded(child: Text(label, style: theme.textTheme.titleSmall)),
        IconButton(
          tooltip: 'Copy',
          visualDensity: VisualDensity.compact,
          icon: const Icon(Icons.copy_outlined, size: 16),
          onPressed: copy.isEmpty
              ? null
              : () => Clipboard.setData(ClipboardData(text: copy)),
        ),
      ],
    );

    final transcript = [
      for (final s in segments)
        '${_wallClock(s['started_at'] as String?)}  ${s['text']}',
    ].join('\n');

    return [
      for (final (key, label) in _sections)
        if ('${summary[key] ?? ''}'.trim().isNotEmpty) ...[
          heading(label, '${summary[key]}'.trim()),
          SelectableText(
            '${summary[key]}'.trim(),
            key: ValueKey('field-recording-$key'),
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 8),
        ],
      if (pending > 0)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Text(
            'Transcribing… $pending left',
            key: const ValueKey('field-recording-pending'),
            style: small?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ),
      if (summary.isEmpty && pending == 0 && segments.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Text('The summary is being written.', style: small),
        ),
      heading('Transcript', transcript),
      if (segments.isEmpty)
        Text(
          chunks == 0
              ? 'Nothing has arrived from the phone yet.'
              : pending > 0
              ? 'Nothing written up yet.'
              : 'Nothing was said.',
          style: small,
        )
      else
        for (final s in segments)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 64,
                  child: Text(
                    _wallClock(s['started_at'] as String?),
                    style: small?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                      fontFeatures: const [FontFeature.tabularFigures()],
                    ),
                  ),
                ),
                Expanded(
                  child: SelectableText(
                    '${s['text']}',
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
          ),
    ];
  }

  /// 10:04:12, the phone's wall clock.
  static String _wallClock(String? iso) {
    final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
    if (at == null) return '';
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(at.hour)}:${two(at.minute)}:${two(at.second)}';
  }
}
