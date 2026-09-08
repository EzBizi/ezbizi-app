import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';
import 'guide_player.dart';
import 'test_player.dart';

/// The Learn tab — layout 1 of
/// `docs/previews/training-layouts.html` (Jose's approved pick).
///
/// Two shelves, "Assigned to you" always drawn first and never filterable
/// away — that was the deciding difference against layout 2's single
/// filtered list. `GET /training/learn` (`LearnController::index`) answers
/// `{assigned: [...], library: [...]}`, no `data` envelope, so every read
/// here goes through `response.body` directly rather than `response.data`.
///
/// Every entry — in either shelf — is a guide (`screen_count`, `screens`,
/// `last_screen`, `completed_at`) or a test (`question_count`, `questions`,
/// `due_for_retaking`, `last_score`, `last_passed`, `last_taken_at`).
/// `library` and `assigned` share the exact same rendering for the fields
/// both carry; `assigned` additionally carries `due_on` (a real assignment)
/// and `due_for_retaking` can be true on a synthetic row with no
/// `assignment_id` at all — a lapsed pass reappearing (LearnController's own
/// docblock, "A lapsed pass reappears here"). Neither `answers` nor the
/// correct option on any question ever appears in this response
/// (`LearnerView::stripAnswerKey`) and this screen renders none of
/// `questions` at all — that is the player's job, not the shelf's.
///
/// `_EntryCard`'s progress bar is drawn only from `last_screen`/
/// `screen_count` — a real fraction a guide's own reading position
/// supports. A test has no "screens seen" to show a fraction of, so its
/// card states its last outcome as text ("Passed 11 Aug" / "Failed 11 Aug" /
/// "Not started") rather than drawing a bar this data does not support.
class LearnShelves extends StatefulWidget {
  const LearnShelves({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<LearnShelves> createState() => _LearnShelvesState();
}

class _LearnShelvesState extends State<LearnShelves> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _assigned = const [];
  List<Map<String, dynamic>> _library = const [];

  /// (type, id) of the card open in the player — `GuidePlayer`/`TestPlayer`
  /// (task 13). Null when neither is open, which is when the two shelves
  /// draw. This is a screen swap, not a modal (house rule: no modals; a
  /// selection is a pane or a screen).
  (String, String)? _selected;

  @override
  void initState() {
    super.initState();
    _load();
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

  /// A count, matching the mockup's subtitle slot ("3 to do · 1 overdue") —
  /// never a second sentence saying the shelf is empty. `emptyText` below
  /// the rule already says that; this returning one too was two sentences
  /// making the identical claim.
  String get _assignedSubtitle {
    if (_assigned.isEmpty) return '';

    final today = _today();
    final overdue = _assigned.where((entry) {
      final due = parseCalendarDay(entry['due_on'] as String?);
      return due != null && due.isBefore(today);
    }).length;

    final base = _assigned.length == 1 ? '1 to do' : '${_assigned.length} to do';
    return overdue == 0 ? base : '$base · $overdue overdue';
  }

  /// Every guide id a test in either shelf names as its own `guide_id` —
  /// `presentTest` already sends it, so no server change is needed to
  /// match a guide against the test that follows it (the mockup's "8
  /// screens · test at the end"). Read across BOTH shelves, not just the
  /// one a card is drawn in: a guide sitting in `assigned` and its test
  /// sitting in `library` (readable but not itself assigned) is an
  /// ordinary shape, not an edge case.
  Set<String> get _guidesWithATest {
    final ids = <String>{};
    for (final entry in [..._assigned, ..._library]) {
      if (entry['type'] != 'test') continue;
      final guideId = entry['guide_id'] as String?;
      if (guideId != null) ids.add(guideId);
    }
    return ids;
  }

  /// Opens the player for a tapped card — a guide entry opens `GuidePlayer`,
  /// a test entry opens `TestPlayer` directly (task 13). Entries with
  /// neither a recognised `type` nor an `id` are inert taps, not a crash.
  void _open(Map<String, dynamic> entry) {
    final type = entry['type'] as String?;
    final id = entry['id'] as String?;
    if (id == null || (type != 'guide' && type != 'test')) return;

    setState(() => _selected = (type!, id));
  }

  void _closePlayer() => setState(() => _selected = null);

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

    final guidesWithATest = _guidesWithATest;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _Shelf(
            title: 'Assigned to you',
            subtitle: _assignedSubtitle,
            emptyText: 'Nothing is assigned to you right now.',
            entries: _assigned,
            session: widget.session,
            assigned: true,
            guidesWithATest: guidesWithATest,
            onOpen: _open,
          ),
          const SizedBox(height: 24),
          _Shelf(
            title: 'Library',
            subtitle: 'Anything you can reach, take it when you like.',
            emptyText: 'There is nothing in your library yet.',
            entries: _library,
            session: widget.session,
            assigned: false,
            guidesWithATest: guidesWithATest,
            onOpen: _open,
          ),
        ],
      ),
    );
  }
}

/// One shelf: a heading with a rule, then a wrap of cards or an empty
/// sentence. Never a table and never a second filtered view of the same
/// list — that collapsing of "assigned" into a togglable chip is exactly
/// what layout 1 was picked over layout 2 to prevent.
class _Shelf extends StatelessWidget {
  const _Shelf({
    required this.title,
    required this.subtitle,
    required this.emptyText,
    required this.entries,
    required this.session,
    required this.assigned,
    required this.guidesWithATest,
    required this.onOpen,
  });

  final String title;
  final String subtitle;
  final String emptyText;
  final List<Map<String, dynamic>> entries;
  final Session session;
  final bool assigned;
  final Set<String> guidesWithATest;
  final ValueChanged<Map<String, dynamic>> onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: [
            Text(title, style: theme.textTheme.titleMedium),
            const SizedBox(width: 9),
            Expanded(
              child: Text(
                subtitle,
                style: theme.textTheme.bodyMedium,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Divider(color: EzBiziTheme.edge(context), height: 1),
        const SizedBox(height: 12),
        if (entries.isEmpty)
          Text(emptyText, style: theme.textTheme.bodyMedium)
        else
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              for (final entry in entries)
                SizedBox(
                  width: 260,
                  child: _EntryCard(
                    entry: entry,
                    session: session,
                    assigned: assigned,
                    guidesWithATest: guidesWithATest,
                    onTap: () => onOpen(entry),
                  ),
                ),
            ],
          ),
      ],
    );
  }
}

/// One guide or one test — the shapes `LearnController::presentGuide` /
/// `presentTest` actually produce, nothing invented from the mockup on top.
class _EntryCard extends StatelessWidget {
  const _EntryCard({
    required this.entry,
    required this.session,
    required this.assigned,
    required this.guidesWithATest,
    required this.onTap,
  });

  final Map<String, dynamic> entry;
  final Session session;
  final bool assigned;
  final VoidCallback onTap;

  /// Every guide id some test in this read names as its own `guide_id` —
  /// see `_LearnShelvesState._guidesWithATest`.
  final Set<String> guidesWithATest;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final me = session.me!;

    final isTest = entry['type'] == 'test';
    final title = entry['title'] as String? ?? '';
    // The mockup's second meta clause: a guide followed by a test reads "N
    // screens · test at the end"; a test with no guide of its own reads
    // "Test only · N questions". A gated test (guide_id set) shows its
    // question count plain — its guide's own card is the one naming the
    // pairing, so saying it twice would not add information.
    final meta = isTest
        ? (entry['guide_id'] == null
              ? 'Test only · ${_count(entry['question_count'], 'question')}'
              : _count(entry['question_count'], 'question'))
        : guidesWithATest.contains(entry['id'])
        ? '${_count(entry['screen_count'], 'screen')} · test at the end'
        : _count(entry['screen_count'], 'screen');

    final dueOn = assigned ? parseCalendarDay(entry['due_on'] as String?) : null;
    final overdue = dueOn != null && dueOn.isBefore(_today());
    final dueForRetaking = entry['due_for_retaking'] == true;

    // `last_screen`/`completed_at` (guide) and `last_score`/`last_passed`/
    // `last_taken_at` (test) — LearnController's own progress fields,
    // added alongside `screens`/`questions` (never trimmed from this
    // payload: Task 13's guide player wants them from the same read).
    final screenCount = entry['screen_count'] is num ? (entry['screen_count'] as num).toInt() : null;
    final lastScreen = entry['last_screen'] is num ? (entry['last_screen'] as num).toInt() : null;
    final completedAt = _parseIso(entry['completed_at'] as String?);
    final lastPassed = entry['last_passed'] as bool?;
    final lastTakenAt = _parseIso(entry['last_taken_at'] as String?);

    // A real fraction off `last_screen`/`screen_count` — never invented for
    // a test, which has no notion of "screens seen". `last_screen` is a
    // 0-indexed high-water mark (ProgressController::seen's own docblock),
    // so the screen actually reached is one more than the stored index.
    final guideFraction = (!isTest && screenCount != null && screenCount > 0)
        ? ((lastScreen != null ? lastScreen + 1 : 0) / screenCount).clamp(0.0, 1.0)
        : null;

    final progressLabel = isTest
        ? switch (lastPassed) {
            null => 'Not started',
            true => lastTakenAt == null ? 'Passed' : 'Passed ${me.display.formatDate(lastTakenAt)}',
            false => lastTakenAt == null ? 'Failed' : 'Failed ${me.display.formatDate(lastTakenAt)}',
          }
        : (completedAt != null
              ? 'Done ${me.display.formatDate(completedAt)}'
              : lastScreen != null
              ? '${lastScreen + 1} of ${screenCount ?? '?'}'
              : 'Not started');

    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: theme.colorScheme.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: overdue
                ? theme.colorScheme.error.withValues(alpha: 0.5)
                : EzBiziTheme.edge(context),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Tag(_scopeLabel(me, entry)),
            const SizedBox(height: 8),
            Text(
              title,
              style: theme.textTheme.titleSmall,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 3),
            Text(meta, style: theme.textTheme.bodyMedium),
            if (guideFraction != null) ...[
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(
                  value: guideFraction,
                  minHeight: 6,
                  backgroundColor: theme.colorScheme.surfaceContainerHighest,
                ),
              ),
            ],
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                if (dueOn != null)
                  Tag(
                    overdue
                        ? 'Was due ${me.display.formatDate(dueOn)}'
                        : 'Due ${me.display.formatDate(dueOn)}',
                    warn: overdue,
                  ),
                if (dueForRetaking) const Tag('Due for retaking', warn: true),
                Tag(progressLabel),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// The place a guide or test belongs to, resolved from the SAME reach `/me`
/// already carries — never a picker, and never a company this entry does not
/// actually name. `company_id`/`franchise_id` are already scope-filtered by
/// `InventoryVisibility::constrain` on the server, so a sibling company's own
/// training never reaches this list to look up in the first place.
///
/// `*InView` (current-scope-narrowed), not `companies`/`franchises` (full
/// reach) — a label lookup by an id the server already vetted never NEEDS
/// full reach, and `*InView` costs nothing extra here: per
/// `InventoryVisibility::constrain`, every id this screen can ever receive
/// already resolves to the same row in either list (company scope: the same
/// set; franchise scope: the one parent company; organization scope:
/// identity — the ancestor-company case that would tell the two apart can
/// never arise). So this switch mislabels nothing today, and it means a row
/// `constrain` could never legitimately produce renders 'Organization'
/// instead of a sibling's name, rather than depending on the server alone to
/// keep that true.
String _scopeLabel(Me me, Map<String, dynamic> entry) {
  final franchiseId = entry['franchise_id'] as String?;
  if (franchiseId != null) {
    final franchise = me.franchisesInView.where((f) => f.id == franchiseId).firstOrNull;
    if (franchise != null) return franchise.name;
  }

  final companyId = entry['company_id'] as String?;
  if (companyId != null) {
    final company = me.companiesInView.where((c) => c.id == companyId).firstOrNull;
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

/// `completed_at`/`last_taken_at` off the wire — an ISO-8601 instant, shown
/// in the reader's own zone (`.toLocal()`), the same conversion
/// `formatStamp` (`core/timestamps.dart`) applies. Null for anything that
/// does not parse, same as that function's own fallback shape.
DateTime? _parseIso(String? iso) => iso == null ? null : DateTime.tryParse(iso)?.toLocal();
