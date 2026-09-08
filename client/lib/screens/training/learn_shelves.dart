import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';

/// The Learn tab — layout 1 of
/// `docs/previews/training-layouts.html` (Jose's approved pick).
///
/// Two shelves, "Assigned to you" always drawn first and never filterable
/// away — that was the deciding difference against layout 2's single
/// filtered list. `GET /training/learn` (`LearnController::index`) answers
/// `{assigned: [...], library: [...]}`, no `data` envelope, so every read
/// here goes through `response.body` directly rather than `response.data`.
///
/// Every entry — in either shelf — is a guide (`screen_count`, `screens`) or
/// a test (`question_count`, `questions`, `due_for_retaking`). `library` and
/// `assigned` share the exact same rendering for the fields both carry;
/// `assigned` additionally carries `due_on` (a real assignment) and
/// `due_for_retaking` can be true on a synthetic row with no `assignment_id`
/// at all — a lapsed pass reappearing (LearnController's own docblock, "A
/// lapsed pass reappears here"). Neither `answers` nor the correct option on
/// any question ever appears in this response (`LearnerView::stripAnswerKey`)
/// and this screen renders none of `questions` at all — that is the player's
/// job, not the shelf's.
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

  String get _assignedSubtitle {
    if (_assigned.isEmpty) return 'Nothing outstanding right now.';

    final today = _today();
    final overdue = _assigned.where((entry) {
      final due = parseCalendarDay(entry['due_on'] as String?);
      return due != null && due.isBefore(today);
    }).length;

    final base = _assigned.length == 1 ? '1 to do' : '${_assigned.length} to do';
    return overdue == 0 ? base : '$base · $overdue overdue';
  }

  @override
  Widget build(BuildContext context) {
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
          ),
          const SizedBox(height: 24),
          _Shelf(
            title: 'Library',
            subtitle: 'Anything you can reach, take it when you like.',
            emptyText: 'There is nothing in your library yet.',
            entries: _library,
            session: widget.session,
            assigned: false,
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
  });

  final String title;
  final String subtitle;
  final String emptyText;
  final List<Map<String, dynamic>> entries;
  final Session session;
  final bool assigned;

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
  });

  final Map<String, dynamic> entry;
  final Session session;
  final bool assigned;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final me = session.me!;

    final isTest = entry['type'] == 'test';
    final title = entry['title'] as String? ?? '';
    final meta = isTest
        ? _count(entry['question_count'], 'question')
        : _count(entry['screen_count'], 'screen');

    final dueOn = assigned ? parseCalendarDay(entry['due_on'] as String?) : null;
    final overdue = dueOn != null && dueOn.isBefore(_today());
    final dueForRetaking = entry['due_for_retaking'] == true;

    return Container(
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
          if (dueOn != null || dueForRetaking) ...[
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
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The place a guide or test belongs to, resolved from the SAME reach `/me`
/// already carries — never a picker, and never a company this entry does not
/// actually name. `company_id`/`franchise_id` are already scope-filtered by
/// `InventoryVisibility::constrain` on the server, so a sibling company's own
/// training never reaches this list to look up in the first place.
String _scopeLabel(Me me, Map<String, dynamic> entry) {
  final franchiseId = entry['franchise_id'] as String?;
  if (franchiseId != null) {
    final franchise = me.franchises.where((f) => f.id == franchiseId).firstOrNull;
    if (franchise != null) return franchise.name;
  }

  final companyId = entry['company_id'] as String?;
  if (companyId != null) {
    final company = me.companies.where((c) => c.id == companyId).firstOrNull;
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
