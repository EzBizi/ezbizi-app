import 'package:flutter/material.dart';

import '../../core/api_client.dart';
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
    final narrow = MediaQuery.sizeOf(context).width < 600;

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
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: scheme.outline,
            ),
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
  /// have done. The lapsed count is boxed and in the error colour.
  Widget _counts(BuildContext context, List<_Row> rows) {
    final scheme = Theme.of(context).colorScheme;

    int of(_State state) => rows.where((r) => r.state == state).length;

    Widget cell(int n, String label, {bool bad = false}) => Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: bad
            ? BoxDecoration(
                border: Border.all(color: scheme.error),
                borderRadius: BorderRadius.circular(8),
              )
            : null,
        child: Column(
          children: [
            Text(
              '$n',
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                color: bad ? scheme.error : null,
                fontWeight: FontWeight.w700,
              ),
            ),
            Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: bad ? scheme.error : scheme.outline,
              ),
            ),
          ],
        ),
      ),
    );

    // ponytail: three counts as drawn; not passed and in progress are listed
    // below but not counted. Add a cell if Jose asks for one.
    return Row(
      key: TrainingCard.countsKey,
      children: [
        cell(of(_State.lapsed), 'Due for retaking', bad: true),
        const SizedBox(width: 8),
        cell(of(_State.passed), 'Passed'),
        const SizedBox(width: 8),
        cell(of(_State.notStarted), 'Not started'),
      ],
    );
  }

  Widget _line(BuildContext context, _Row row) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: Text(
              row.title,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium,
            ),
          ),
          Tag(row.state.label, warn: row.state.bad),
          if (row.detail.isNotEmpty) ...[
            const SizedBox(width: 10),
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
              Flexible(child: Tag(row.state.label, warn: row.state.bad)),
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
        return _Row(title, _State.lapsed, expires == null ? '' : 'lapsed $expires');
      }
      if (json['passed'] == true) {
        return _Row(
          title,
          _State.passed,
          [
            if (score != null) '$score%',
            if (expires != null) 'runs out $expires',
          ].join(' · '),
        );
      }
      if (json['passed'] == false) {
        return _Row(title, _State.notPassed, score == null ? '' : '$score%');
      }
      return _Row(title, _State.notStarted, '');
    }

    final completed = _date(json['completed_at']);

    if (completed != null) {
      return _Row(title, _State.passed, 'completed $completed');
    }
    if (json['last_screen'] != null) {
      final total = json['screen_count'];

      return _Row(
        title,
        _State.inProgress,
        total == null ? '' : 'screen ${json['last_screen']} of $total',
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
  const _Row(this.title, this.state, this.detail);

  final String title;
  final _State state;
  final String detail;
}
