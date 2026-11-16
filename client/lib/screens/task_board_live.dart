/// An open board keeps itself current (Jose, 2026-11-16: a status changed in
/// Monday showed only after leaving the Task Board and coming back). The
/// screen asks `GET task-boards/{id}/changes?since=...` every
/// [liveEvery] while it is on screen and the app is in front, and folds the
/// answer into the board it holds with [mergeBoardChanges]: rows are
/// replaced where they are, never reloaded, so scroll, panels and open
/// editors stay put.
library;

import 'dart:convert';

typedef LiveJson = Map<String, dynamic>;

const liveEvery = Duration(seconds: 10);

/// [board] with [changes] folded in, or null when nothing it holds moved.
///
/// - A task it holds is replaced in place, unless its copy is newer (a write
///   of this person's that the reply raced).
/// - A task it does not hold is added only when it is new since [since] (at
///   the top of its group) or is a subtask of a row it holds; a change to a
///   task on a page not loaded yet only moves the group's count.
/// - Deleted ids leave.
/// - [hold]: a cell is open for editing, so rows are replaced but none are
///   added or removed (the table's rows are by position). [merged.held] says
///   some were held back, and the caller keeps its cursor to fetch them again.
({LiveJson? board, bool held}) mergeBoardChanges(
  LiveJson board,
  LiveJson changes,
  String since, {
  required LiveJson Function(LiveJson board, LiveJson task) adopt,
  bool hold = false,
}) {
  final tasks = (board['tasks'] as List? ?? const []).cast<LiveJson>().toList();
  final at = <Object?, int>{for (final (i, t) in tasks.indexed) t['id']: i};
  final from = DateTime.tryParse(since);
  final gone = {
    for (final id in (changes['deleted'] as List? ?? const []))
      if (at.containsKey(id)) id,
  };
  var moved = false;
  var held = false;
  final top = <LiveJson>[], subs = <LiveJson>[];
  for (final raw in (changes['tasks'] as List? ?? const []).cast<LiveJson>()) {
    final i = at[raw['id']];
    if (i != null) {
      final mine = DateTime.tryParse('${tasks[i]['updated_at']}');
      final theirs = DateTime.tryParse('${raw['updated_at']}');
      if (mine != null && theirs != null && theirs.isBefore(mine)) continue;
      // A write's reply does not say a row is rolled up; the old row does.
      final rolled = tasks[i]['rolled_up_from'];
      final next = adopt(board, {
        ...raw,
        if (raw['rolled_up_from'] == null && rolled != null)
          'rolled_up_from': rolled,
      });
      tasks[i] = next;
      moved = true;
      continue;
    }
    final parent = raw['parent_task_id'];
    final created = DateTime.tryParse('${raw['created_at']}');
    final isNew = from != null && created != null && !created.isBefore(from);
    if (parent != null ? at.containsKey(parent) : isNew) {
      if (hold) {
        held = true;
        continue;
      }
      (parent == null ? top : subs).add(adopt(board, raw));
    }
  }
  if (hold && gone.isNotEmpty) {
    held = true;
    gone.clear();
  }

  final groups = changes['groups'];
  final totals = changes['group_totals'];
  final regrouped =
      groups is List &&
      groups.isNotEmpty &&
      jsonEncode(groups) != jsonEncode(board['groups']);
  if (!moved &&
      !regrouped &&
      gone.isEmpty &&
      top.isEmpty &&
      subs.isEmpty &&
      totals == null) {
    return (board: null, held: held);
  }
  final next = [
    ...top,
    for (final t in tasks)
      if (!gone.contains(t['id']) && !gone.contains(t['parent_task_id'])) t,
    ...subs,
  ];

  final out = <String, dynamic>{...board, 'tasks': next};
  if (regrouped) out['groups'] = groups;
  final had = board['group_totals'];
  if (totals is Map && had is Map) {
    // Count and footers are the server's; what is shown is what this board
    // loaded, which is also where Show more picks up.
    final known = {
      for (final g in (out['groups'] as List? ?? const []).cast<Map>())
        '${g['id']}',
    };
    final loaded = <String, int>{};
    for (final t in next) {
      if (t['parent_task_id'] != null || t['rolled_up_from'] != null) continue;
      final g = '${t['group_id']}';
      final key = known.contains(g) ? g : '';
      loaded[key] = (loaded[key] ?? 0) + 1;
    }
    out['group_totals'] = {
      for (final key in {
        ...had.keys.map((k) => '$k'),
        ...totals.keys.map((k) => '$k'),
      })
        key: () {
          final server = (totals[key] as Map?) ?? const {};
          final count = (server['count'] as num?)?.toInt() ?? 0;
          final shown = loaded[key] ?? 0;
          return {
            ...?(had[key] as Map?),
            'count': count,
            'shown': shown,
            'has_more': count > shown,
            'footers':
                server['footers'] ?? (had[key] as Map?)?['footers'] ?? const {},
          };
        }(),
    };
  }
  return (board: out, held: held);
}
