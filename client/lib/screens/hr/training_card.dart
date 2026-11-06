import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/tag.dart';

/// One person's training, on their HR record.
///
/// Read from Training's `GET /training/records?user=` and drawn here because the
/// record screen is HR's; Training owns the data and the words. Layouts are
/// Jose's picks of 2026-09-09 from `docs/previews/hr-record-training-card-layouts.html`:
/// desktop 3 (three counts, then the rows) and mobile 6 (a card per row, most
/// urgent first).
///
/// Three things in it are settled with Training and are not decoration:
///
///  - It never says "certification". That word belongs to the HR dashboard's
///    *Expiring certifications*, which counts licence dates the employee typed
///    and is theirs to renew. This card counts our own test passes lapsing,
///    which is ours to re-assign. The footnote at the bottom says so, always,
///    because the two panels sit on one screen at similar size and are both
///    about time running out.
///  - "Due for retaking" means ALREADY lapsed, deliberately unlike the
///    dashboard's 60-day lookahead, so it cannot hide that the harm has started.
///  - A refusal is a normal state, not an error path. The endpoint is bounded by
///    reach over the PERSON, not the content, and the card shows the server's
///    sentence verbatim under a lock rather than an empty card.
class TrainingCard extends StatefulWidget {
  const TrainingCard({
    super.key,
    required this.api,
    required this.userId,
    required this.formats,
  });

  final ApiClient api;
  final String userId;
  final DisplayFormats formats;

  /// The counts strip of desktop 3; absent on the phone layout.
  static const countsKey = Key('training-counts');

  @override
  State<TrainingCard> createState() => _TrainingCardState();
}

class _TrainingCardState extends State<TrainingCard> {
  ApiResponse? _response;

  @override
  void initState() {
    super.initState();
    widget.api.get('/training/records', {'user': widget.userId}).then((r) {
      if (mounted) setState(() => _response = r);
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final response = _response;

    // The CARD's width, not the window's. This widget is built to be placed
    // in a slot; in a side panel on a desktop the window is wide and the
    // card is not, and the strip would be drawn into a column that cannot
    // hold it (UI/UX, 2026-09-09).
    return LayoutBuilder(
      builder: (context, constraints) =>
          _card(context, theme, response, narrow: constraints.maxWidth < 600),
    );
  }

  Widget _card(
    BuildContext context,
    ThemeData theme,
    ApiResponse? response, {
    required bool narrow,
  }) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(Icons.school_outlined, size: 20),
                const SizedBox(width: 8),
                Text('Training', style: theme.textTheme.titleMedium),
              ],
            ),
            const SizedBox(height: 12),
            if (response == null)
              const LinearProgressIndicator()
            else if (response.forbidden)
              _refused(context, response.reason)
            else if (!response.ok)
              ErrorNote('Their training could not be read: ${response.reason}')
            else ...[
              ..._body(context, _rows(response.data), narrow),
              _footnote(context),
            ],
          ],
        ),
      ),
    );
  }

  // ── the states ───────────────────────────────────────────────────

  Widget _refused(BuildContext context, String sentence) {
    final scheme = Theme.of(context).colorScheme;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(Icons.lock_outline, size: 20, color: scheme.outline),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            // The server's, verbatim: it says which of the two remedies —
            // a permission on a role, or an assignment widened — this is.
            sentence,
            style: Theme.of(
              context,
            ).textTheme.bodyMedium?.copyWith(color: scheme.outline),
          ),
        ),
      ],
    );
  }

  List<Widget> _body(BuildContext context, List<_Row> rows, bool narrow) {
    if (rows.isEmpty) {
      return [
        Text(
          'Nothing has been assigned to them, and they have not started '
          'anything.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
      ];
    }

    if (narrow) {
      return [for (final row in rows) _mini(context, row)];
    }

    return [
      _counts(context, rows),
      const SizedBox(height: 8),
      for (final row in rows) _line(context, row),
    ];
  }

  /// Desktop 3's strip: is anything wrong with this person, before what they
  /// have done. The first cell is boxed and in the error colour.
  ///
  /// Three cells that PARTITION the five states (UI/UX ruling, 2026-09-09):
  /// a strip that counted only the drawn three read "0 · 0 · 0" above two
  /// failed tests, answering "nothing wrong" while the list said otherwise.
  /// The rows keep their exact pills; the strip summarises. The first cell is
  /// not "due for retaking" — that word is Training's and means a LAPSED
  /// pass, and somebody who never passed is not retaking anything.
  Widget _counts(BuildContext context, List<_Row> rows) {
    final scheme = Theme.of(context).colorScheme;

    int of(Set<_State> states) =>
        rows.where((r) => states.contains(r.state)).length;

    // Design 190: equal tiles, each with a tinted icon, so the strip reads by
    // colour before it is read by number.
    Widget cell(int n, String label, IconData icon, Tone tone) {
      final tint = ToneColours.of(context, tone);

      return Expanded(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            border: Border.all(
              color: tone == Tone.bad ? scheme.error : scheme.outlineVariant,
            ),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: tint.fill,
                  borderRadius: BorderRadius.circular(9),
                ),
                child: Icon(icon, size: 18, color: tint.ink),
              ),
              const SizedBox(width: 12),
              Flexible(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '$n',
                      style: Theme.of(context).textTheme.headlineSmall
                          ?.copyWith(
                            color: tone == Tone.bad ? scheme.error : null,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                    Text(
                      label,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(
                        context,
                      ).textTheme.bodySmall?.copyWith(color: scheme.outline),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }

    return Row(
      key: TrainingCard.countsKey,
      children: [
        // Red only when somebody actually needs a pass: "Needs a pass 0" in red
        // read as a problem (deep dive 2026-11-02 #7).
        cell(
          of({_State.lapsed, _State.notPassed}),
          'Needs a pass',
          Icons.priority_high,
          of({_State.lapsed, _State.notPassed}) > 0 ? Tone.bad : Tone.neutral,
        ),
        const SizedBox(width: 12),
        cell(
          of({_State.notStarted, _State.inProgress}),
          'Outstanding',
          Icons.schedule,
          Tone.neutral,
        ),
        const SizedBox(width: 12),
        cell(of({_State.passed}), 'Passed', Icons.check, Tone.ok),
      ],
    );
  }

  /// Design 190: aligned columns (course, status, score, then the dates) so
  /// the pills and the scores can be compared down the card.
  Widget _line(BuildContext context, _Row row) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            flex: 3,
            child: Text(
              row.title,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium,
            ),
          ),
          SizedBox(
            width: 150,
            child: Align(alignment: Alignment.centerLeft, child: _tag(row)),
          ),
          SizedBox(width: 110, child: _score(context, row)),
          const SizedBox(width: 12),
          Expanded(
            flex: 2,
            child: Text(
              row.detail,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.outline,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Green passed, amber lapsed, red not passed (design 190).
  Widget _tag(_Row row) => Tag(
    row.state.label,
    good: row.state == _State.passed,
    warn: row.state == _State.lapsed,
    bad: row.state == _State.notPassed,
  );

  /// A bar for a test's score; nothing for a guide.
  Widget _score(BuildContext context, _Row row) {
    final score = row.score;
    if (score == null) return const SizedBox.shrink();

    return ClipRRect(
      borderRadius: BorderRadius.circular(3),
      child: LinearProgressIndicator(
        value: (score / 100).clamp(0, 1).toDouble(),
        minHeight: 6,
        backgroundColor: ToneColours.of(context, Tone.neutral).fill,
        color: row.state == _State.notPassed
            ? Theme.of(context).colorScheme.error
            : EzBiziTheme.clockIn(context),
      ),
    );
  }

  /// Mobile 6: a small card per row, tinted when lapsed. The order does the
  /// work that headings would.
  Widget _mini(BuildContext context, _Row row) {
    final theme = Theme.of(context);

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(
        color: row.state == _State.lapsed
            ? theme.colorScheme.errorContainer.withValues(alpha: 0.35)
            : theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(row.title, style: theme.textTheme.bodyMedium),
          const SizedBox(height: 6),
          // Both halves shorten: at 390 a pill and a date together are wider
          // than the card, and a row that runs off the edge hides the date.
          Row(
            children: [
              Flexible(child: _tag(row)),
              if (row.detail.isNotEmpty) ...[
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    row.detail,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.outline,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }

  /// The ownership line. Real UI, always present with a populated card —
  /// see the class note for why it is the load-bearing part of the design.
  Widget _footnote(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.outline,
    );
    final strong = muted?.copyWith(fontWeight: FontWeight.w600);

    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Divider(height: 1),
          const SizedBox(height: 8),
          Text.rich(
            TextSpan(
              style: muted,
              children: [
                TextSpan(text: 'Due for retaking', style: strong),
                const TextSpan(
                  text: ' is ours to re-assign — separate from HR’s ',
                ),
                TextSpan(text: 'Expiring certifications', style: strong),
                const TextSpan(text: ', which is theirs to renew.'),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ── what the endpoint says, in the card's five states ────────────

  List<_Row> _rows(Object? data) {
    final rows = [
      for (final raw in (data as List<dynamic>? ?? const []))
        if (raw is Map) _row(Map<String, dynamic>.from(raw)),
    ];

    // Most urgent first. Stable, so the server's alphabetical order holds
    // within a state.
    rows.sort((a, b) => a.state.index.compareTo(b.state.index));

    return rows;
  }

  _Row _row(Map<String, dynamic> json) {
    final title = json['title'] as String? ?? '';

    if (json['type'] == 'test') {
      final score = json['score'];
      final expires = _date(json['expires_on']);

      if (json['due_for_retaking'] == true) {
        return _Row(
          title,
          _State.lapsed,
          expires == null ? '' : 'lapsed $expires',
        );
      }
      if (json['passed'] == true) {
        return _Row(
          title,
          _State.passed,
          [
            if (score != null) '$score%',
            if (expires != null) 'runs out $expires',
          ].join(' · '),
          score: score is num ? score : null,
        );
      }
      if (json['passed'] == false) {
        return _Row(
          title,
          _State.notPassed,
          score == null ? '' : '$score%',
          score: score is num ? score : null,
        );
      }
      return _Row(title, _State.notStarted, '');
    }

    final completed = _date(json['completed_at']);

    if (completed != null) {
      return _Row(title, _State.passed, 'completed $completed');
    }
    // `last_screen` is a 0-indexed high-water mark (ProgressController::seen),
    // so the screen reached is one more than stored — the same arithmetic as
    // Training's own Learn card, so a guide reads the same on both screens.
    // Null is "not started"; zero is "has seen the first screen".
    if (json['last_screen'] case final num reached) {
      final total = json['screen_count'];

      return _Row(
        title,
        _State.inProgress,
        total == null ? '' : 'screen ${reached.toInt() + 1} of $total',
      );
    }
    return _Row(title, _State.notStarted, '');
  }

  String? _date(Object? iso) {
    final at = iso is String ? DateTime.tryParse(iso) : null;

    return at == null ? null : widget.formats.formatDate(at.toLocal());
  }
}

/// In the order the card lists them.
enum _State {
  lapsed('Due for retaking', bad: true),
  notPassed('Not passed', bad: true),
  notStarted('Not started'),
  inProgress('In progress'),
  passed('Passed');

  const _State(this.label, {this.bad = false});

  final String label;
  final bool bad;
}

class _Row {
  const _Row(this.title, this.state, this.detail, {this.score});

  final String title;
  final _State state;
  final String detail;

  /// A test's percentage, for the bar; null for a guide.
  final num? score;
}
