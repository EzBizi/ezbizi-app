import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tag.dart';
import 'guide_player.dart';
import 'test_player.dart';

/// The Learn tab — option 1 (desktop) and 4 (phone) of
/// `docs/previews/training-learn-layouts.html`, UI queue 41 (Jose,
/// 2026-10-01: "library is just showing everything the same, guides, and
/// tests with no difference").
///
/// Where you left off comes first, with one Continue; "Assigned to you" sits
/// beside it and is never filtered away (the rule Learn 1 was picked for).
/// Then the library: each guide on one card with the test that follows it
/// (a test names its guide in `guide_id`), so a guide and a test look like
/// two different things and read as two steps of one. Status chips and a
/// search narrow the library, never the assigned box.
///
/// `GET /training/learn` (`LearnController::index`) answers
/// `{assigned: [...], library: [...]}` with no `data` envelope. A guide is
/// `screen_count`/`screens` (its topics, each with an optional `chapter` and
/// `blocks`)/`last_screen`/`completed_at`; a test is `question_count`/
/// `pass_mark`/`max_attempts`/`attempts_toward_cap`/`requires_guide`/
/// `due_for_retaking`/`last_score`/`last_passed`/`last_taken_at`. Assigned
/// entries add `due_on`. Neither answers nor correct options ever arrive
/// here (`LearnerView::stripAnswerKey`).
class LearnShelves extends StatefulWidget {
  const LearnShelves({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<LearnShelves> createState() => _LearnShelvesState();
}

/// A guide and the test that follows it; either may be missing.
class _Pair {
  _Pair(this.guide, this.test);

  final Map<String, dynamic>? guide;
  final Map<String, dynamic>? test;

  Map<String, dynamic> get first => guide ?? test!;

  String get status {
    final g = guide, t = test;
    if (t?['due_for_retaking'] == true) return 'Due for retaking';
    final guideDone = g == null || g['completed_at'] != null;
    final testDone = t == null || t['last_passed'] == true;
    if (guideDone && testDone) return 'Done';
    final started =
        g?['last_screen'] != null ||
        g?['completed_at'] != null ||
        t?['last_taken_at'] != null;
    return started ? 'In progress' : 'Not started';
  }

  bool matches(String query) =>
      query.isEmpty ||
      [guide?['title'], test?['title']].any(
        (t) => '${t ?? ''}'.toLowerCase().contains(query.toLowerCase()),
      );
}

const _statuses = ['In progress', 'Not started', 'Due for retaking', 'Done'];

class _LearnShelvesState extends State<LearnShelves> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _assigned = const [];
  List<Map<String, dynamic>> _library = const [];

  /// The status chip picked over the library; null is All.
  String? _filter;
  final _search = TextEditingController();

  /// (type, id) of the entry open in the player. A screen swap inside the
  /// tab, never a modal (house rule).
  (String, String)? _selected;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _assigned.isEmpty && _library.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/training/learn');
    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _assigned = _rows(response.body['assigned']);
      _library = _rows(response.body['library']);
    });
  }

  List<Map<String, dynamic>> _rows(Object? raw) => raw is List
      ? raw
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .toList()
      : const [];

  /// "3 to do · 1 overdue": a count, never a second sentence saying the box
  /// is empty (the box's own line says that).
  String get _assignedSubtitle {
    if (_assigned.isEmpty) return '';

    final today = _today();
    final overdue = _assigned.where((entry) {
      final due = parseCalendarDay(entry['due_on'] as String?);
      return due != null && due.isBefore(today);
    }).length;

    final base = _assigned.length == 1
        ? '1 to do'
        : '${_assigned.length} to do';
    return overdue == 0 ? base : '$base · $overdue overdue';
  }

  /// Every entry once, the assigned copy first (it carries `due_on`).
  List<Map<String, dynamic>> get _everything {
    final seen = <String>{};
    return [
      for (final entry in [..._assigned, ..._library])
        if (seen.add('${entry['type']}:${entry['id']}')) entry,
    ];
  }

  /// Each guide with the first test naming it; every other test alone.
  List<_Pair> get _pairs {
    final all = _everything;
    final guides = [
      for (final e in all)
        if (e['type'] == 'guide') e,
    ];
    final tests = [
      for (final e in all)
        if (e['type'] == 'test') e,
    ];
    final used = <Map<String, dynamic>>{};
    final pairs = <_Pair>[];
    for (final guide in guides) {
      final test = tests
          .where((t) => t['guide_id'] == guide['id'] && !used.contains(t))
          .firstOrNull;
      if (test != null) used.add(test);
      pairs.add(_Pair(guide, test));
    }
    for (final test in tests) {
      if (!used.contains(test)) pairs.add(_Pair(null, test));
    }
    return pairs;
  }

  /// The test that follows a guide, wherever it sits.
  Map<String, dynamic>? _testOf(Map<String, dynamic> guide) => _everything
      .where((e) => e['type'] == 'test' && e['guide_id'] == guide['id'])
      .firstOrNull;

  Map<String, dynamic>? _guideOf(Map<String, dynamic> test) => _everything
      .where((e) => e['type'] == 'guide' && e['id'] == test['guide_id'])
      .firstOrNull;

  /// The guide half-read most recently: `last_read_at` is when a topic of it
  /// was last finished (Training b4a2fc7a). Without one, list order decides,
  /// assigned first.
  Map<String, dynamic>? get _continue {
    final open = [
      for (final e in _everything)
        if (e['type'] == 'guide' &&
            e['last_screen'] != null &&
            e['completed_at'] == null)
          e,
    ];
    Map<String, dynamic>? best;
    DateTime? bestAt;
    for (final e in open) {
      final at = DateTime.tryParse('${e['last_read_at'] ?? ''}');
      if (best == null || (at != null && (bestAt == null || at.isAfter(bestAt)))) {
        best = e;
        bestAt = at;
      }
    }
    return best;
  }

  void _open(Map<String, dynamic> entry) {
    final type = entry['type'] as String?;
    final id = entry['id'] as String?;
    if (id == null || (type != 'guide' && type != 'test')) return;

    setState(() => _selected = (type!, id));
  }

  void _closePlayer() {
    setState(() => _selected = null);
    // Progress moved while the player was open.
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final selected = _selected;
    if (selected != null) {
      final (type, id) = selected;
      return type == 'test'
          ? TestPlayer(
              api: widget.api,
              session: widget.session,
              testId: id,
              onLeave: _closePlayer,
            )
          : GuidePlayer(
              api: widget.api,
              session: widget.session,
              guideId: id,
              onLeave: _closePlayer,
            );
    }

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, box) {
          final wide = box.maxWidth >= 900;
          final columns = box.maxWidth >= 1100
              ? 3
              : box.maxWidth >= 700
              ? 2
              : 1;
          // The assigned box is two-fifths of the top row: under ~520 its
          // rows put the tag and the button under the title.
          final top = [
            _continueCard(context, wide),
            _assignedBox(context, compact: !wide || box.maxWidth < 1320),
          ];

          return ListView(
            padding: EdgeInsets.all(wide ? 24 : 16),
            children: [
              if (wide)
                IntrinsicHeight(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Expanded(flex: 3, child: top[0]),
                      const SizedBox(width: 16),
                      Expanded(flex: 2, child: top[1]),
                    ],
                  ),
                )
              else ...[
                top[0],
                const SizedBox(height: 12),
                top[1],
              ],
              const SizedBox(height: 28),
              ..._libraryBlock(context, wide, columns),
            ],
          );
        },
      ),
    );
  }

  // ── continue where you left off ──────────────────────────────────

  Widget _continueCard(BuildContext context, bool wide) {
    final theme = Theme.of(context);
    final guide = _continue;

    final box = BoxDecoration(
      color: theme.colorScheme.primaryContainer.withValues(alpha: 0.45),
      border: Border.all(color: theme.colorScheme.primary.withValues(alpha: 0.3)),
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
    );

    if (guide == null) {
      return Container(
        key: const Key('learn-continue'),
        padding: const EdgeInsets.all(16),
        decoration: box,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _label(context, 'Continue where you left off'),
            Text(
              'Nothing half-read. Open any guide below and it waits for you '
              'here.',
              style: theme.textTheme.bodyMedium,
            ),
          ],
        ),
      );
    }

    final topics = _topics(guide);
    final total = _int(guide['screen_count']) ?? topics.length;
    final seen = (_int(guide['last_screen']) ?? -1) + 1;
    final next = seen < topics.length ? topics[seen] : null;
    final chapters = _chapters(topics);
    final chapter = next == null ? null : _str(next['chapter']);
    final test = _testOf(guide);

    final button = FilledButton.icon(
      key: const Key('learn-continue-go'),
      onPressed: () => _open(guide),
      icon: const Icon(Icons.play_arrow, size: 18),
      label: const Text('Continue'),
    );

    return Container(
      key: const Key('learn-continue'),
      padding: const EdgeInsets.all(16),
      decoration: box,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _label(context, 'Continue where you left off'),
          Row(
            children: [
              _iconBox(context, Icons.menu_book_outlined),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      guide['title'] as String? ?? '',
                      style: theme.textTheme.titleMedium,
                    ),
                    if (next != null)
                      Text(
                        'Next: ${_str(next['title']) ?? 'Topic ${seen + 1}'} · '
                        'topic ${seen + 1}',
                        style: theme.textTheme.bodyMedium,
                      ),
                  ],
                ),
              ),
              if (wide) ...[const SizedBox(width: 12), button],
            ],
          ),
          const SizedBox(height: 10),
          _bar(context, total == 0 ? 0 : seen / total),
          const SizedBox(height: 4),
          Text(
            [
              '$seen of $total topics',
              if (chapter != null && chapters.length > 1)
                'chapter ${chapters.indexOf(chapter) + 1} of ${chapters.length}',
            ].join(' · '),
            style: theme.textTheme.bodySmall,
          ),
          if (!wide) ...[const SizedBox(height: 10), button],
          if (test != null) ...[
            const SizedBox(height: 10),
            Text(
              'Its test, ${test['title']} · '
              '${_count(test['question_count'], 'question')} · '
              'pass at ${test['pass_mark']}% · '
              '${_locked(test) ? 'after the guide' : 'open now'}',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }

  // ── assigned to you ──────────────────────────────────────────────

  Widget _assignedBox(BuildContext context, {required bool compact}) {
    final theme = Theme.of(context);
    final me = widget.session.me!;

    return Container(
      key: const Key('learn-assigned'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text('Assigned to you', style: theme.textTheme.titleSmall),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  _assignedSubtitle,
                  style: theme.textTheme.bodySmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (_assigned.isEmpty)
            Text(
              'Nothing is assigned to you right now.',
              style: theme.textTheme.bodyMedium,
            ),
          for (final entry in _assigned)
            Builder(
              builder: (context) {
                final what = Row(
                  children: [
                    _iconBox(
                      context,
                      entry['type'] == 'test'
                          ? Icons.quiz_outlined
                          : Icons.menu_book_outlined,
                      small: true,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            entry['title'] as String? ?? '',
                            style: theme.textTheme.titleSmall,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          Text(
                            _assignedMeta(me, entry),
                            style: theme.textTheme.bodySmall,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ],
                      ),
                    ),
                  ],
                );
                final act = [
                  ..._dueTags(me, entry),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    onPressed: () => _open(entry),
                    child: Text(_verb(entry)),
                  ),
                ];

                // Narrow, the tag and the button go under the title rather
                // than squeezing it to two letters.
                return Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: compact
                      ? Column(
                          children: [
                            what,
                            const SizedBox(height: 4),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.end,
                              children: act,
                            ),
                          ],
                        )
                      : Row(
                          children: [
                            Expanded(child: what),
                            const SizedBox(width: 8),
                            ...act,
                          ],
                        ),
                );
              },
            ),
        ],
      ),
    );
  }

  String _assignedMeta(Me me, Map<String, dynamic> entry) {
    if (entry['type'] == 'test') {
      final taken = _parseIso(entry['last_taken_at'] as String?);
      final score = entry['last_score'];
      return entry['last_passed'] == true && taken != null
          ? 'Test · you passed with $score% on ${me.display.formatDate(taken)}'
          : 'Test · ${_count(entry['question_count'], 'question')}';
    }
    final test = _testOf(entry);
    return test == null
        ? 'Guide · ${_count(entry['screen_count'], 'topic')}'
        : 'Guide, then its ${_int(test['question_count']) ?? 0}-question test';
  }

  List<Widget> _dueTags(Me me, Map<String, dynamic> entry) {
    final due = parseCalendarDay(entry['due_on'] as String?);
    final overdue = due != null && due.isBefore(_today());
    return [
      if (due != null)
        Tag(
          overdue
              ? 'Was due ${me.display.formatDate(due)}'
              : 'Due ${me.display.formatDate(due)}',
          warn: overdue,
        ),
      if (entry['due_for_retaking'] == true)
        const Tag('Due for retaking', warn: true),
    ];
  }

  String _verb(Map<String, dynamic> entry) {
    if (entry['due_for_retaking'] == true) return 'Retake';
    if (entry['type'] == 'guide') {
      return entry['last_screen'] != null && entry['completed_at'] == null
          ? 'Continue'
          : 'Start';
    }
    return entry['last_taken_at'] == null ? 'Start' : 'Open';
  }

  // ── the library: each guide with its test ────────────────────────

  List<Widget> _libraryBlock(BuildContext context, bool wide, int columns) {
    final theme = Theme.of(context);
    final pairs = _pairs;
    final guides = pairs.where((p) => p.guide != null).length;
    final tests = pairs.where((p) => p.test != null).length;
    final counts = {
      for (final s in _statuses) s: pairs.where((p) => p.status == s).length,
    };
    final shown = [
      for (final pair in pairs)
        if ((_filter == null || pair.status == _filter) &&
            pair.matches(_search.text.trim()))
          pair,
    ];

    final search = SizedBox(
      width: wide ? 260 : double.infinity,
      child: ListSearch(
        key: const Key('learn-search'),
        controller: _search,
        hint: 'Find a guide or test',
        onChanged: (_) => setState(() {}),
      ),
    );

    final chips = Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        ChoiceChip(
          label: const Text('All'),
          selected: _filter == null,
          onSelected: (_) => setState(() => _filter = null),
        ),
        for (final s in _statuses)
          if (counts[s]! > 0 || _filter == s)
            ChoiceChip(
              key: Key('learn-chip-$s'),
              label: Text('$s · ${counts[s]}'),
              selected: _filter == s,
              onSelected: (_) =>
                  setState(() => _filter = _filter == s ? null : s),
            ),
      ],
    );

    return [
      Wrap(
        crossAxisAlignment: WrapCrossAlignment.end,
        spacing: 10,
        children: [
          Text('Library', style: theme.textTheme.titleMedium),
          if (pairs.isNotEmpty)
            Text(
              'Each guide with the test that follows it · '
              '${_count(guides, 'guide')}, ${_count(tests, 'test')}',
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
      const SizedBox(height: 10),
      if (pairs.isNotEmpty) ...[
        if (wide)
          Row(
            children: [
              Expanded(child: chips),
              const SizedBox(width: 12),
              search,
            ],
          )
        else ...[
          search,
          const SizedBox(height: 8),
          chips,
        ],
        const SizedBox(height: 12),
      ],
      if (pairs.isEmpty)
        Text(
          'There is nothing in your library yet.',
          style: theme.textTheme.bodyMedium,
        )
      else if (shown.isEmpty)
        Text('Nothing here matches.', style: theme.textTheme.bodyMedium)
      else
        Column(
          key: const Key('learn-library'),
          children: [
            // Rows of equal-height cards (sibling tiles are one size).
            for (var i = 0; i < shown.length; i += columns)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: IntrinsicHeight(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (var j = i; j < i + columns; j++) ...[
                        if (j > i) const SizedBox(width: 12),
                        Expanded(
                          child: j < shown.length
                              ? _pairCard(context, shown[j])
                              : const SizedBox.shrink(),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
          ],
        ),
    ];
  }

  Widget _pairCard(BuildContext context, _Pair pair) {
    final theme = Theme.of(context);
    final me = widget.session.me!;
    final head = pair.first;
    final due = parseCalendarDay(
      (pair.guide?['due_on'] ?? pair.test?['due_on']) as String?,
    );
    final overdue = due != null && due.isBefore(_today());
    final status = pair.status;

    return Container(
      key: Key('pair-${head['id']}'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(
          color: overdue
              ? theme.colorScheme.error.withValues(alpha: 0.5)
              : EzBiziTheme.edge(context),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Row(
                  children: [
                    Flexible(child: Tag(_scopeLabel(me, head))),
                    if (due != null) ...[
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          overdue
                              ? 'Was due ${me.display.formatDate(due)}'
                              : 'Due ${me.display.formatDate(due)}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: overdue ? theme.colorScheme.error : null,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 8),
              StatusPill(status, tone: _tone(status)),
            ],
          ),
          const SizedBox(height: 12),
          if (pair.guide case final guide?)
            _guideRow(context, me, guide)
          else
            _missing(context, 'No guide comes before this test'),
          Align(
            alignment: Alignment.centerLeft,
            child: Container(
              margin: const EdgeInsets.only(left: 16),
              width: 2,
              height: 12,
              color: EzBiziTheme.edge(context),
            ),
          ),
          if (pair.test case final test?)
            _testRow(context, me, test, pair.guide)
          else
            _missing(context, 'No test follows this guide'),
        ],
      ),
    );
  }

  Widget _guideRow(BuildContext context, Me me, Map<String, dynamic> guide) {
    final theme = Theme.of(context);
    final topics = _topics(guide);
    final total = _int(guide['screen_count']) ?? topics.length;
    final last = _int(guide['last_screen']);
    final completed = _parseIso(guide['completed_at'] as String?);
    final chapters = _chapters(topics).length;
    final videos = [
      for (final t in topics)
        for (final b in t['blocks'] is List ? t['blocks'] as List : const [])
          if (b is Map && b['type'] == 'video') b,
    ].length;

    // A real fraction off `last_screen` (a 0-indexed high-water mark), never
    // invented: one more than the stored index is the topic reached.
    final fraction = completed != null
        ? 1.0
        : total > 0 && last != null
        ? ((last + 1) / total).clamp(0.0, 1.0)
        : 0.0;

    return InkWell(
      key: Key('guide-${guide['id']}'),
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      onTap: () => _open(guide),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              _iconBox(context, Icons.menu_book_outlined),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      guide['title'] as String? ?? '',
                      style: theme.textTheme.titleSmall,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    Text(
                      [
                        'Guide',
                        if (chapters > 1) _count(chapters, 'chapter'),
                        _count(total, 'topic'),
                        if (videos > 0) _count(videos, 'video'),
                      ].join(' · '),
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: _bar(context, fraction)),
              const SizedBox(width: 10),
              Text(
                completed != null
                    ? 'Completed ${me.display.formatDate(completed)}'
                    : last != null
                    ? '${last + 1} of $total'
                    : 'Not started',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _testRow(
    BuildContext context,
    Me me,
    Map<String, dynamic> test,
    Map<String, dynamic>? guide,
  ) {
    final theme = Theme.of(context);
    final passed = test['last_passed'] as bool?;
    final taken = _parseIso(test['last_taken_at'] as String?);
    final score = _int(test['last_score']);
    final max = _int(test['max_attempts']);
    final used = _int(test['attempts_toward_cap']) ?? 0;
    final tries = _int(test['attempts_taken']) ?? used;

    final (String pill, Tone tone, String detail) = test['due_for_retaking'] == true
        ? (
            'Due for retaking',
            Tone.caution,
            taken == null ? '' : 'passed ${me.display.formatDate(taken)}',
          )
        : switch (passed) {
            true => (
              'Passed',
              Tone.ok,
              [
                if (taken != null) me.display.formatDate(taken),
                if (max != null && tries > 0) 'attempt $tries of $max',
              ].join(' · '),
            ),
            false => (
              'Not passed',
              Tone.bad,
              max == null ? '' : '${max - used} of $max attempts left',
            ),
            null => (
              _locked(test, guide) ? 'After the guide' : 'Not started',
              Tone.neutral,
              [
                if (!_locked(test, guide)) 'open now',
                if (max != null) _count(max, 'attempt'),
              ].join(' · '),
            ),
          };

    return InkWell(
      key: Key('test-${test['id']}'),
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      onTap: () => _open(test),
      child: Row(
        children: [
          _iconBox(context, Icons.quiz_outlined, dark: true),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  test['title'] as String? ?? '',
                  style: theme.textTheme.titleSmall,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                Text(
                  'Test · ${_count(test['question_count'], 'question')} · '
                  'pass at ${test['pass_mark']}%',
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 4),
                Wrap(
                  spacing: 6,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    StatusPill(pill, tone: tone),
                    if (detail.isNotEmpty)
                      Text(detail, style: theme.textTheme.bodySmall),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text(
            score == null ? '—' : '$score%',
            style: theme.textTheme.titleMedium?.copyWith(
              color: score == null
                  ? theme.colorScheme.onSurfaceVariant
                  : ToneColours.of(context, passed == true ? Tone.ok : Tone.bad)
                        .ink,
            ),
          ),
        ],
      ),
    );
  }

  /// A test that waits for its guide: `requires_guide` and the guide not
  /// finished.
  bool _locked(Map<String, dynamic> test, [Map<String, dynamic>? guide]) {
    if (test['requires_guide'] != true) return false;
    final g = guide ?? _guideOf(test);
    return g == null || g['completed_at'] == null;
  }

  // ── small pieces ─────────────────────────────────────────────────

  Widget _label(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Text(
      text.toUpperCase(),
      style: Theme.of(context).textTheme.labelSmall?.copyWith(
        letterSpacing: 1.1,
        fontWeight: FontWeight.w800,
        color: Theme.of(context).colorScheme.primary,
      ),
    ),
  );

  Widget _iconBox(
    BuildContext context,
    IconData icon, {
    bool small = false,
    bool dark = false,
  }) {
    final scheme = Theme.of(context).colorScheme;
    final size = small ? 28.0 : 34.0;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: dark ? scheme.inverseSurface : scheme.primaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Icon(
        icon,
        size: small ? 16 : 19,
        color: dark ? scheme.onInverseSurface : scheme.primary,
      ),
    );
  }

  Widget _bar(BuildContext context, double value) => ClipRRect(
    borderRadius: BorderRadius.circular(4),
    child: LinearProgressIndicator(
      value: value,
      minHeight: 6,
      backgroundColor: Theme.of(context).colorScheme.surfaceContainerHighest,
    ),
  );

  Widget _missing(BuildContext context, String text) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: theme.textTheme.bodySmall?.copyWith(fontStyle: FontStyle.italic),
      ),
    );
  }

  Tone _tone(String status) => switch (status) {
    'Done' => Tone.ok,
    'In progress' => Tone.info,
    'Due for retaking' => Tone.caution,
    _ => Tone.neutral,
  };
}

/// A guide's topics, as the learn read sends them.
List<Map<String, dynamic>> _topics(Map<String, dynamic> guide) => [
  for (final t in guide['screens'] is List ? guide['screens'] as List : const [])
    if (t is Map) Map<String, dynamic>.from(t),
];

/// Chapter names in order of first appearance.
List<String> _chapters(List<Map<String, dynamic>> topics) {
  final out = <String>[];
  for (final t in topics) {
    final c = _str(t['chapter']);
    if (c != null && !out.contains(c)) out.add(c);
  }
  return out;
}

String? _str(Object? v) {
  final s = v is String ? v.trim() : null;
  return s == null || s.isEmpty ? null : s;
}

int? _int(Object? v) => v is num ? v.toInt() : null;

/// The place a guide or test belongs to, resolved from the SAME reach `/me`
/// already carries — never a company this entry does not name. `*InView`
/// rather than full reach: an id the server already scope-filtered never
/// needs more, and a row it could not legitimately send reads
/// "Organization" instead of a sibling's name.
String _scopeLabel(Me me, Map<String, dynamic> entry) {
  final franchiseId = entry['franchise_id'] as String?;
  if (franchiseId != null) {
    final franchise = me.franchisesInView
        .where((f) => f.id == franchiseId)
        .firstOrNull;
    if (franchise != null) return franchise.name;
  }

  final companyId = entry['company_id'] as String?;
  if (companyId != null) {
    final company = me.companiesInView
        .where((c) => c.id == companyId)
        .firstOrNull;
    if (company != null) return company.name;
  }

  return 'Organization';
}

String _count(Object? value, String noun) {
  final n = value is num ? value.toInt() : 0;
  return '$n $noun${n == 1 ? '' : 's'}';
}

DateTime _today() {
  final now = DateTime.now();
  return DateTime(now.year, now.month, now.day);
}

/// An ISO-8601 instant shown in the reader's own zone, as `formatStamp` does.
DateTime? _parseIso(String? iso) =>
    iso == null ? null : DateTime.tryParse(iso)?.toLocal();
