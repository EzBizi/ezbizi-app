import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../widgets/call_text.dart';
import '../../widgets/status_pill.dart';

import '../../core/api_client.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import '../phone/recording_playback_bar.dart';
import '../phone/recording_player.dart';

/// One person's scored calls on the Scoring screen (Jose, 2026-10-14: "the
/// latest 3 calls for each user, with a search bar to search for specific
/// ones as well only for that selected user. and the individual score of
/// said call") and "the best one and the worst one to compare". The latest
/// three plus the best and worst; a search by number or by words shows every
/// match in the dates.
class ScoringPersonCalls extends StatefulWidget {
  const ScoringPersonCalls({
    super.key,
    required this.api,
    required this.person,
    required this.span,
  });

  final ApiClient api;
  final String person;
  final DateSpan span;

  @override
  State<ScoringPersonCalls> createState() => _ScoringPersonCallsState();
}

class _ScoringPersonCallsState extends State<ScoringPersonCalls> {
  final RecordingPlayer _player = createRecordingPlayer();
  List<Map<String, dynamic>> _calls = const [];
  String _query = '';
  String? _error;
  String? _open;
  bool _loading = true;
  Timer? _debounce;
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ScoringPersonCalls old) {
    super.didUpdateWidget(old);
    if (old.person != widget.person || old.span != widget.span) _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _player.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() => _loading = true);
    final response = await widget.api.get('/reports/scoring/calls', {
      'person': widget.person,
      'from': widget.span.fromKey,
      'to': widget.span.toKey,
      if (_query.trim().isNotEmpty) 'q': _query.trim(),
    });
    if (!mounted || request != _request) return;
    setState(() {
      _loading = false;
      if (response.ok) {
        _error = null;
        _calls = [
          for (final c in (response.data as List? ?? const []))
            (c as Map).cast<String, dynamic>(),
        ];
      } else {
        _error = response.message;
      }
    });
  }

  void _search(String value) {
    _query = value;
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _load);
  }

  Future<List<int>?> _recording(String id) async =>
      (await widget.api.getBytesResponse(
        '/reports/scoring/calls/$id/recording',
      )).bytes;

  static String _when(Object? iso) {
    final t = DateTime.tryParse('$iso')?.toLocal();
    if (t == null) return '';
    final h = t.hour % 12 == 0 ? 12 : t.hour % 12;
    return '${t.month}/${t.day} $h:${t.minute.toString().padLeft(2, '0')}'
        '${t.hour < 12 ? 'am' : 'pm'}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('scoring-calls-search'),
          onChanged: _search,
          decoration: const InputDecoration(
            isDense: true,
            prefixIcon: Icon(Icons.search),
            hintText: 'Search their calls by number or words',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 8),
        if (_error != null)
          ErrorNote(_error!)
        else if (_loading && _calls.isEmpty)
          const LinearProgressIndicator()
        else if (_calls.isEmpty)
          Text(
            _query.trim().isEmpty
                ? 'No scored calls in these dates.'
                : 'No calls match.',
            style: muted,
          )
        else
          for (final c in _calls) _row(context, c, muted),
        if (_query.trim().isEmpty && _calls.isNotEmpty)
          Text(
            'The latest three, and the best and worst in these dates. '
            'Search to find any other.',
            style: muted,
          ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> c, TextStyle? muted) {
    final theme = Theme.of(context);
    final id = '${c['call_id']}';
    final open = _open == id;
    final score = c['score'] as num?;
    final recording = c['recording_id']?.toString();
    // The best and worst in the dates, beside the latest (Jose, 2026-10-14).
    final tags = [
      for (final t in (c['tags'] as List? ?? const []))
        if (t == 'best' || t == 'worst') '$t',
    ];

    return Card(
      key: ValueKey('scoring-call-$id'),
      margin: const EdgeInsets.only(bottom: 6),
      child: InkWell(
        onTap: () => setState(() => _open = open ? null : id),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(_when(c['started_at']), style: muted),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: Copyable(
                        '${c['caller_number'] ?? 'unknown number'}',
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                  ),
                  score == null
                      ? Text(
                          '—',
                          key: ValueKey('scoring-call-score-$id'),
                          style: theme.textTheme.titleSmall,
                        )
                      : StatusPill(
                          '${score.round()}/10',
                          key: ValueKey('scoring-call-score-$id'),
                          tone: scoreTone(score),
                        ),
                  Icon(open ? Icons.expand_less : Icons.expand_more, size: 18),
                ],
              ),
              if (tags.isNotEmpty)
                Row(
                  children: [
                    for (final tag in tags)
                      Padding(
                        padding: const EdgeInsets.only(right: 6),
                        child: Text(
                          tag == 'best' ? 'Best' : 'Worst',
                          key: ValueKey('scoring-call-$tag'),
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: tag == 'best'
                                ? theme.colorScheme.primary
                                : theme.colorScheme.error,
                          ),
                        ),
                      ),
                  ],
                ),
              if (open && c['strength'] != null) ...[
                const SizedBox(height: 6),
                const ToneHeading('Did well', Tone.ok),
                Copyable('${c['strength']}'),
              ],
              if (c['improve'] != null) ...[
                const SizedBox(height: 6),
                const ToneHeading('To improve', Tone.caution),
                Copyable('${c['improve']}'),
              ],
              if (open) ...[
                if (recording != null) ...[
                  const SizedBox(height: 8),
                  RecordingPlaybackBar(
                    key: ValueKey('scoring-call-play-$id'),
                    player: _player,
                    dense: true,
                    load: () => _recording(recording),
                    downloadName: RecordingPlaybackBar.nameFor(
                      c['started_at'],
                      c['caller_number'],
                    ),
                  ),
                ],
                if (c['transcript'] != null) ...[
                  const SizedBox(height: 8),
                  CallTranscriptText('${c['transcript']}'),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }
}
