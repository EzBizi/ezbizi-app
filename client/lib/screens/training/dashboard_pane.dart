import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';

/// One guide or test's own audience — `GET /training/dashboard/{id}/people`
/// (`DashboardController::people`). Every row in its `data` is one person:
/// `{user_id, name, state, due_on, ...detail}`.
///
/// `detail` is EITHER a guide's own `{last_screen, screen_count,
/// completed_at}` OR a test's own `{score, passed, expires_on}` — there is
/// no `type` field anywhere in this module's dashboard reads (`dashboard_
/// list.dart`'s own docblock: the `/dashboard` list itself never says
/// whether a row is a guide or a test either). So [_PersonRow] decides per
/// PERSON, from whichever of those keys that person's own row actually
/// carries, rather than assuming every row in the audience looks the same —
/// a person who is assigned but has neither started nor been scored carries
/// neither set of keys at all, and renders with no progress/score chip,
/// which is the honest answer for "nothing has happened yet".
///
/// `state` is one of `passed` / `retaking` / `overdue` / `in_progress` /
/// `not_started` (`DashboardController::stateOf`) — [_StateTag] is the one
/// place that turns it into words, and it says "Due for retaking", never
/// "certification" (the module's own rule — see `dashboard_list.dart`'s own
/// docblock and `training_dashboard_tab_test.dart`).
class DashboardPane extends StatefulWidget {
  const DashboardPane({
    super.key,
    required this.api,
    required this.session,
    required this.itemId,
    required this.title,
    required this.row,
    required this.onBack,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final String itemId;
  final String title;

  /// The SAME row `TrainingDashboard` already holds from `GET
  /// /training/dashboard` — `{assigned, passed, in_progress, overdue,
  /// retaking}` — used for the four stat numbers at the top of this pane.
  /// Never refetched here and never recomputed from the people list below:
  /// one source for a count that already exists is one fewer place the two
  /// could disagree.
  final Map<String, dynamic> row;

  final VoidCallback onBack;

  /// Below `InventoryScreen.splitAbove` this pane is the WHOLE body, and
  /// this is what draws Back rather than the caller drawing a second one —
  /// the same split `ItemPane` (Inventory) and `VehiclePane` (Fleet) use.
  final bool showBack;

  @override
  State<DashboardPane> createState() => _DashboardPaneState();
}

class _DashboardPaneState extends State<DashboardPane> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _people = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant DashboardPane oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.itemId != widget.itemId) _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/training/dashboard/${widget.itemId}/people');
    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _people = _mapList(response.data);
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(widget.showBack ? 8 : 20, 12, 20, 8),
          child: Row(
            children: [
              if (widget.showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: widget.onBack,
                ),
              Expanded(
                child: Text(
                  widget.title,
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
          child: _RowStats(row: widget.row),
        ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _people.isEmpty
                ? const Center(child: Text('Nobody is assigned to, or has attempted, this yet.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
                    itemCount: _people.length,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (context, i) => _PersonRow(person: _people[i], session: widget.session),
                  ),
          ),
        ),
      ],
    );
  }
}

/// The four numbers `docs/previews/training-layouts.html` layout 6 draws at
/// the top of the pane — passed / in progress / overdue / due for retaking
/// — off [row] only, never off the people list this pane also fetches. Two
/// different reads of the same fact could disagree by a race between them;
/// one read never can.
class _RowStats extends StatelessWidget {
  const _RowStats({required this.row});

  final Map<String, dynamic> row;

  int _of(String key) => row[key] is num ? (row[key] as num).toInt() : 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    Widget stat(int value, String label, {bool warn = false}) => Padding(
      padding: const EdgeInsets.only(right: 24, bottom: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '$value',
            style: theme.textTheme.headlineSmall?.copyWith(color: warn ? scheme.error : null),
          ),
          Text(label, style: theme.textTheme.labelSmall),
        ],
      ),
    );

    return Wrap(
      children: [
        stat(_of('passed'), 'passed'),
        stat(_of('in_progress'), 'in progress'),
        stat(_of('overdue'), 'overdue', warn: _of('overdue') > 0),
        stat(_of('retaking'), 'due for retaking', warn: _of('retaking') > 0),
      ],
    );
  }
}

class _PersonRow extends StatelessWidget {
  const _PersonRow({required this.person, required this.session});

  final Map<String, dynamic> person;
  final Session session;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final me = session.me!;

    final name = person['name'] as String? ?? 'Someone';
    final state = person['state'] as String? ?? 'not_started';
    final dueOn = parseCalendarDay(person['due_on'] as String?);

    // A guide's own progress — a real fraction off `last_screen`/
    // `screen_count`, the identical shape `LearnShelves` already draws;
    // never invented for a test, which has no "screens seen".
    final screenCount = person['screen_count'] is num ? (person['screen_count'] as num).toInt() : null;
    final lastScreen = person['last_screen'] is num ? (person['last_screen'] as num).toInt() : null;
    final fraction = (screenCount != null && screenCount > 0)
        ? ((lastScreen != null ? lastScreen + 1 : 0) / screenCount).clamp(0.0, 1.0)
        : null;

    // A test's own score. `containsKey` rather than a null check on the
    // VALUE — `testDetailByUser` sends `score: null` for an attempt that
    // failed to record one, which must still say "No score yet" rather
    // than being read as "not a test, say nothing".
    final hasScore = person.containsKey('score');
    final score = person['score'] is num ? (person['score'] as num).toInt() : null;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(name, style: theme.textTheme.titleSmall),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              _StateTag(state),
              if (dueOn != null) Tag('Due ${me.display.formatDate(dueOn)}'),
              if (hasScore) Tag(score == null ? 'No score yet' : '$score%'),
            ],
          ),
          if (fraction != null) ...[
            const SizedBox(height: 8),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                value: fraction,
                minHeight: 6,
                backgroundColor: theme.colorScheme.surfaceContainerHighest,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// `state` (`DashboardController::stateOf`) turned into the one word a
/// reader sees — "Due for retaking", never "certification".
class _StateTag extends StatelessWidget {
  const _StateTag(this.state);

  final String state;

  @override
  Widget build(BuildContext context) {
    final (label, warn) = switch (state) {
      'passed' => ('Passed', false),
      'retaking' => ('Due for retaking', true),
      'overdue' => ('Overdue', true),
      'in_progress' => ('In progress', false),
      _ => ('Not started', false),
    };

    return Tag(label, warn: warn);
  }
}

List<Map<String, dynamic>> _mapList(dynamic raw) {
  if (raw is! List) return const [];
  return [
    for (final entry in raw)
      if (entry is Map) Map<String, dynamic>.from(entry),
  ];
}
