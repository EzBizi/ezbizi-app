import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import '../phone/recording_player.dart';

/// Field › Recordings (UI queue 102, design 4: a table, and the player in a
/// side panel; in place on a phone).
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
    this.player,
  });

  final ApiClient api;

  /// field.listen: the person filter is offered.
  final bool canListen;

  /// field.manage: Keep and Stop keeping.
  final bool canKeep;

  /// For tests.
  final RecordingPlayer? player;

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
  late final RecordingPlayer _player = widget.player ?? createRecordingPlayer();
  StreamSubscription<RecordingPlayback>? _sub;
  RecordingPlayback _playback = const RecordingPlayback();
  int _seq = 0;
  bool _playingAll = false;
  bool _keeping = false;
  final _reason = TextEditingController();
  String? _panelError;

  @override
  void initState() {
    super.initState();
    _sub = _player.changes.listen(_onPlayback);
    _load();
  }

  @override
  void dispose() {
    _sub?.cancel();
    _searchDebounce?.cancel();
    _player.dispose();
    _reason.dispose();
    super.dispose();
  }

  static String _ymd(DateTime d) =>
      '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';

  Future<void> _load() async {
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });
    final response = await widget.api.get('/field/recordings', {
      'user_id': ?_person,
      if (_day != null) 'date': _ymd(_day!),
      if (_query.trim().isNotEmpty) 'q': _query.trim(),
    });
    if (!mounted) return;
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

  // ----------------------------------------------------------- playing

  /// The pieces play one after another, as one recording.
  void _onPlayback(RecordingPlayback p) {
    final ended =
        _playback.playing &&
        !p.playing &&
        p.loaded &&
        p.position >= p.duration - const Duration(milliseconds: 300);
    setState(() => _playback = p);
    final chunks = (_open?['chunks'] as num?)?.toInt() ?? 0;
    if (ended && _playingAll && _seq + 1 < chunks) _playSeq(_seq + 1);
    if (ended && _seq + 1 >= chunks) setState(() => _playingAll = false);
  }

  Future<void> _playSeq(int seq) async {
    final id = _open?['id'];
    if (id == null) return;
    setState(() {
      _seq = seq;
      _playingAll = true;
      _panelError = null;
    });
    final bytes = await widget.api.getBytes('/field/recordings/$id/audio/$seq');
    if (!mounted || _open?['id'] != id) return;
    if (bytes == null) {
      setState(() {
        _playingAll = false;
        _panelError = 'That minute could not be loaded.';
      });
      return;
    }
    try {
      await _player.play(bytes);
    } on RecordingPlaybackFailure catch (e) {
      if (mounted) {
        setState(() {
          _playingAll = false;
          _panelError = e.message;
        });
      }
    }
  }

  Future<void> _toggle() async {
    if (_playback.playing) {
      await _player.pause();
      setState(() => _playingAll = false);
    } else if (_playback.loaded && _playback.position < _playback.duration) {
      setState(() => _playingAll = true);
      await _player.resume();
    } else {
      await _playSeq(
        _seq >= ((_open?['chunks'] as num?)?.toInt() ?? 1) - 1 ? 0 : _seq,
      );
    }
  }

  void _openRow(Map<String, dynamic> row) {
    _player.stop();
    setState(() {
      _open = row;
      _seq = 0;
      _playingAll = false;
      _playback = const RecordingPlayback();
      _keeping = false;
      _panelError = null;
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
                    label: Text(_day == null ? 'Any day' : _ymd(_day!)),
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
                  SizedBox(
                    width: 260,
                    height: 40,
                    child: TextField(
                      key: const ValueKey('field-recordings-search'),
                      onChanged: _search,
                      textAlignVertical: TextAlignVertical.center,
                      decoration: const InputDecoration(
                        isDense: true,
                        hintText: 'Search person, job or reason',
                        prefixIcon: Icon(Icons.search, size: 18),
                        prefixIconConstraints: BoxConstraints(minWidth: 36),
                        contentPadding: EdgeInsets.symmetric(horizontal: 8),
                        border: OutlineInputBorder(),
                      ),
                    ),
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
    // Where in the whole recording, counting the minutes before this piece.
    final at = Duration(minutes: _seq) + _playback.position;
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
                onPressed: () {
                  _player.stop();
                  setState(() => _open = null);
                },
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
          Row(
            children: [
              IconButton.filled(
                key: const ValueKey('field-recording-play'),
                tooltip: _playback.playing ? 'Pause' : 'Play',
                icon: Icon(_playback.playing ? Icons.pause : Icons.play_arrow),
                onPressed: chunks == 0 ? null : _toggle,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: LinearProgressIndicator(
                  value: total.inMilliseconds == 0
                      ? 0
                      : (at.inMilliseconds / total.inMilliseconds).clamp(0, 1),
                ),
              ),
              const SizedBox(width: 10),
              Text(_clock(at), style: small),
            ],
          ),
          if (chunks == 0)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'Nothing has arrived from the phone yet.',
                style: small,
              ),
            ),
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
}
