import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_search.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';

/// One guide or test's own audience — `GET /training/dashboard/{id}/people`
/// (`DashboardController::people`). Every row in its `data` is one person:
/// `{user_id, name, state, due_on, ...detail}`.
///
/// `detail` is EITHER a guide's own `{last_screen, screen_count,
/// completed_at}` OR a test's own `{score, passed, expires_on}` — no `type`
/// field on a PERSON row (`DashboardController::people` never added one;
/// see `DashboardController::row`'s own docblock on why the omission there
/// is deliberate — the audience is unambiguously the one item [widget.row]
/// already carries a `kind` for). So [_PersonRow] decides per PERSON, from
/// whichever of those keys that person's own row actually carries, rather
/// than assuming every row in the audience looks the same — a person who is
/// assigned but has neither started nor been scored carries neither set of
/// keys at all, and renders with no progress/score chip, which is the
/// honest answer for "nothing has happened yet".
///
/// [widget.row] itself DOES carry a `kind` (`'guide'` or `'test'`) — see
/// `dashboard_list.dart`'s own docblock — which is what lets [_AssignPane]
/// below post the right one of `guide_id`/`test_id`.
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
  String _query = '';

  /// Swaps the stats+people area for [_AssignPane] via `setState` — the
  /// same in-body pattern `TrainingSettingsTab._open`/`LearnShelves._selected`
  /// already use (their own docblocks): never a modal, never a
  /// `Navigator.push`. The header row (title, Back) stays put; only the
  /// content below the divider changes.
  bool _assigning = false;

  /// `training.assign` — the fourth of the module's four permissions, the
  /// one this control exists for. A control that is offered must work
  /// (`training_screen.dart`'s own rule for its tab chips, `_canMonitor`/
  /// `_canManage`): checked here the identical way, off `session.me`,
  /// rather than drawing the button and letting the first `POST` 403.
  bool get _canAssign => widget.session.me?.can('training.assign') ?? false;

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

    final response = await widget.api.get(
      '/training/dashboard/${widget.itemId}/people',
    );
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
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          widget.title,
          showBack: widget.showBack,
          onBack: widget.onBack,
          ellipsis: true,
        ),
        if (!_assigning) ...[
          Padding(
            key: const ValueKey('tour-training-dashboard-counts'),
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
            child: _RowStats(row: widget.row),
          ),
          // Layout 6's own "＋ Assign to more people"
          // (docs/previews/training-layouts.html) — offered only with
          // `training.assign` (see [_canAssign]'s own docblock).
          if (_canAssign)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
              child: Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton.icon(
                  key: const ValueKey('tour-training-dashboard-assign'),
                  onPressed: () => setState(() => _assigning = true),
                  icon: const Icon(Icons.add, size: 16),
                  label: const Text('Assign to more people'),
                ),
              ),
            ),
        ],
        const Divider(height: 1),
        Expanded(
          child: _assigning
              ? _AssignPane(
                  api: widget.api,
                  session: widget.session,
                  itemId: widget.itemId,
                  // `kind` — see `DashboardController::row`'s own docblock —
                  // is what says which of `guide_id`/`test_id` to post. A
                  // missing `kind` used to fall back to 'guide' silently,
                  // which would post `guide_id` for what might be a test —
                  // a wrong write with nothing red anywhere. Cast rather
                  // than fall back: a missing `kind` is a server bug, and
                  // it must fail loudly here rather than mis-post there.
                  kind: widget.row['kind'] as String,
                  onCancel: () => setState(() => _assigning = false),
                  onAssigned: () {
                    setState(() => _assigning = false);
                    _load();
                  },
                )
              : AsyncBody(
                  key: const ValueKey('tour-training-dashboard-people'),
                  loading: _loading,
                  error: _error,
                  onRetry: _load,
                  child: _people.isEmpty
                      // Says what SHOWED UP, never what exists: this list is
                      // bounded by reach over the PERSON (DashboardController::
                      // people -> reachablePeople), so "nobody is assigned"
                      // would be FALSE to a franchise manager whose colleagues
                      // are assigned and simply not theirs to see. "This one"
                      // rather than "this test" because the pane covers a guide
                      // OR a test, and naming the object would be wrong half the
                      // time.
                      ? const Center(
                          child: Text('Nobody to show for this one.'),
                        )
                      : Builder(
                          builder: (context) {
                            // Every list has search (phone audit 2026-11-04:
                            // 43 people on one test, none findable).
                            final q = _query.trim().toLowerCase();
                            final shown = [
                              for (final p in _people)
                                if (q.isEmpty ||
                                    '${p['name'] ?? ''}'.toLowerCase().contains(
                                      q,
                                    ))
                                  p,
                            ];
                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Padding(
                                  padding: const EdgeInsets.fromLTRB(
                                    20,
                                    12,
                                    20,
                                    0,
                                  ),
                                  child: ListSearch(
                                    key: const ValueKey(
                                      'training-people-search',
                                    ),
                                    hint: 'Find a person',
                                    onChanged: (v) =>
                                        setState(() => _query = v),
                                  ),
                                ),
                                Expanded(
                                  child: ListView.separated(
                                    padding: const EdgeInsets.fromLTRB(
                                      20,
                                      12,
                                      20,
                                      24,
                                    ),
                                    itemCount: shown.length,
                                    separatorBuilder: (_, _) =>
                                        const Divider(height: 1),
                                    itemBuilder: (context, i) => _PersonRow(
                                      // Keyed to the PERSON: this row holds
                                      // expansion state, and an unkeyed row in a
                                      // rebuilt list keeps the state of whoever
                                      // stood in that slot before.
                                      key: ValueKey(shown[i]['user_id']),
                                      person: shown[i],
                                      session: widget.session,
                                    ),
                                  ),
                                ),
                              ],
                            );
                          },
                        ),
                ),
        ),
      ],
    );
  }
}

/// The in-body form behind "＋ Assign to more people" — never a modal (house
/// rule; see `DashboardPane`'s own comment on how it is swapped in).
///
/// Two candidate sources, both off `GET /training/assignees`
/// (`AssignmentsController::assignees`) rather than `/hr/people` or
/// `/roles`: those are gated on `hr.view`/`roles.view`, which somebody
/// holding only `training.assign` need not have — see that endpoint's own
/// docblock. `type=user` never lists somebody the write would then refuse;
/// `type=role` lists every role but marks the ones outside the assigner's
/// own organization reach, so this draws a disabled row with a reason
/// rather than a shorter list with no explanation for the gap.
class _AssignPane extends StatefulWidget {
  const _AssignPane({
    required this.api,
    required this.session,
    required this.itemId,
    required this.kind,
    required this.onCancel,
    required this.onAssigned,
  });

  final ApiClient api;
  final Session session;
  final String itemId;

  /// 'guide' or 'test' — decides which of `guide_id`/`test_id` is posted.
  final String kind;

  final VoidCallback onCancel;

  /// Called once the `POST` succeeds — [DashboardPane] closes this pane and
  /// reloads the people list, so a newly-assigned person appears without a
  /// second tap.
  final VoidCallback onAssigned;

  @override
  State<_AssignPane> createState() => _AssignPaneState();
}

class _AssignPaneState extends State<_AssignPane> {
  static const _typeUser = 'user';
  static const _typeRole = 'role';

  String _assigneeType = _typeUser;
  String? _assigneeId;

  final _search = TextEditingController();
  final _dueOn = TextEditingController();

  List<Map<String, dynamic>> _userResults = const [];
  List<Map<String, dynamic>> _roleResults = const [];
  bool _rolesLoaded = false;
  bool _searching = false;
  bool _submitting = false;
  String? _error;

  /// `AssignmentsController::userCandidates`'s own signal that the list
  /// shown is not the whole of who is reachable — see that method's own
  /// docblock. Never a reason to hide any of `_userResults`; just a hint
  /// that a narrower search would find somebody not shown here.
  bool _truncated = false;

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _searchUsers('');
  }

  @override
  void dispose() {
    _search.dispose();
    _dueOn.dispose();
    super.dispose();
  }

  void _selectType(String type) {
    setState(() {
      _assigneeType = type;
      _assigneeId = null;
      _error = null;
    });

    if (type == _typeRole && !_rolesLoaded) _loadRoles();
  }

  Future<void> _searchUsers(String term) async {
    setState(() => _searching = true);

    final response = await widget.api.get('/training/assignees', {
      'type': _typeUser,
      if (term.isNotEmpty) 'search': term,
    });
    if (!mounted) return;

    setState(() {
      _searching = false;
      if (response.ok) {
        _userResults = _mapList(response.data);
        _truncated = response.body['truncated'] == true;
      } else {
        _error = response.reason;
      }
    });
  }

  Future<void> _loadRoles() async {
    final response = await widget.api.get('/training/assignees', {
      'type': _typeRole,
    });
    if (!mounted) return;

    setState(() {
      _rolesLoaded = true;
      if (response.ok) {
        _roleResults = _mapList(response.data);
      } else {
        _error = response.reason;
      }
    });
  }

  Future<void> _submit() async {
    final assigneeId = _assigneeId;
    if (assigneeId == null) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    final dueOn = _dueOn.text.trim();

    final response = await widget.api.post('/training/assignments', {
      widget.kind == 'guide' ? 'guide_id' : 'test_id': widget.itemId,
      'assignee_type': _assigneeType,
      'assignee_id': assigneeId,
      if (dueOn.isNotEmpty) 'due_on': dueOn,
    });
    if (!mounted) return;

    setState(() => _submitting = false);

    if (!response.ok) {
      // The server's own sentence, never a generic failure — a refusal here
      // is always one of two named things (AssignmentReach): the assignee's
      // account is not active, or they/their role sit outside the
      // assigner's own §4 reach.
      setState(() => _error = response.reason);
      return;
    }

    widget.onAssigned();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 8),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  'Assign to more people',
                  style: theme.textTheme.titleSmall,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              TextButton(
                onPressed: widget.onCancel,
                child: const Text('Cancel'),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ChoiceChip(
                label: const Text('A person'),
                selected: _assigneeType == _typeUser,
                onSelected: (_) => _selectType(_typeUser),
              ),
              ChoiceChip(
                label: const Text('A role'),
                selected: _assigneeType == _typeRole,
                onSelected: (_) => _selectType(_typeRole),
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: ErrorNote(_error!),
          ),
        if (_assigneeType == _typeUser) ...[
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: TextField(
              key: const Key('training-assign-people-search'),
              controller: _search,
              decoration: const InputDecoration(
                labelText: 'Search by name or email',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              onChanged: _searchUsers,
            ),
          ),
          if (_truncated && !_searching)
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 4, 20, 0),
              child: Text(
                'More people match — refine your search to narrow the list.',
                style: TextStyle(fontSize: 12),
              ),
            ),
          const SizedBox(height: 8),
          Expanded(
            child: _searching
                ? const Center(child: CircularProgressIndicator())
                : _userList(),
          ),
        ] else ...[
          Expanded(
            // A failed fetch still flips `_rolesLoaded` (below) so the
            // spinner does not spin forever — but that alone would render
            // `_roleList()`'s own "no roles yet" empty state right under
            // the `ErrorNote` above: two contradictory statements about
            // the same failed request. The `ErrorNote` already says what
            // happened; show nothing else here when it is showing.
            child: !_rolesLoaded
                ? const Center(child: CircularProgressIndicator())
                : (_error != null ? const SizedBox.shrink() : _roleList()),
          ),
        ],
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              CalendarDayField(
                controller: _dueOn,
                formats: _me.display,
                label: 'Due (optional)',
              ),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: (_assigneeId == null || _submitting)
                    ? null
                    : _submit,
                child: _submitting
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Assign'),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _userList() {
    if (_userResults.isEmpty) {
      return const Center(
        child: Text('Nobody matches — try a different name or email.'),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      itemCount: _userResults.length,
      itemBuilder: (context, i) {
        final person = _userResults[i];
        final id = person['id'] as String?;

        return ListTile(
          dense: true,
          selected: id != null && id == _assigneeId,
          title: Text(
            person['name'] as String? ?? '',
            overflow: TextOverflow.ellipsis,
          ),
          subtitle: Text(
            person['email'] as String? ?? '',
            overflow: TextOverflow.ellipsis,
          ),
          trailing: (id != null && id == _assigneeId)
              ? const Icon(Icons.check)
              : null,
          onTap: id == null ? null : () => setState(() => _assigneeId = id),
        );
      },
    );
  }

  Widget _roleList() {
    if (_roleResults.isEmpty) {
      // A DELIBERATE world-claim, and it depends on a fact: roles are
      // ORG-WIDE, so "this organization has none" is true for every
      // reader. The day roles gain a company or franchise dimension this
      // sentence becomes false and NOTHING FAILS - no test, no analyzer,
      // and no reader who could tell. It is stated here so the person
      // adding that dimension finds it (UI/UX, 2026-09-09).
      return const Center(child: Text('This organization has no roles yet.'));
    }

    return ListView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      itemCount: _roleResults.length,
      itemBuilder: (context, i) {
        final role = _roleResults[i];
        final id = role['id'] as String?;
        final assignable = role['assignable'] == true;

        return ListTile(
          dense: true,
          enabled: assignable,
          selected: id != null && id == _assigneeId,
          title: Text(
            role['name'] as String? ?? '',
            overflow: TextOverflow.ellipsis,
          ),
          // Shown and refused rather than filtered out — the same
          // "not yours to give" convention `HrPeopleController`'s own role
          // list uses, so "we have no such role" and "not mine to hand out"
          // stay distinguishable.
          subtitle: assignable
              ? null
              : const Text(
                  'Not yours to give — needs organization-wide reach.',
                ),
          trailing: (id != null && id == _assigneeId)
              ? const Icon(Icons.check)
              : null,
          onTap: (!assignable || id == null)
              ? null
              : () => setState(() => _assigneeId = id),
        );
      },
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
            style: theme.textTheme.headlineSmall?.copyWith(
              color: warn ? scheme.error : null,
            ),
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

class _PersonRow extends StatefulWidget {
  const _PersonRow({super.key, required this.person, required this.session});

  final Map<String, dynamic> person;
  final Session session;

  @override
  State<_PersonRow> createState() => _PersonRowState();
}

class _PersonRowState extends State<_PersonRow> {
  /// How many attempts are drawn before the "Show all N" affordance.
  ///
  /// UI/UX's ruling, 2026-09-09, and the measurement behind it is that this
  /// row sits inside a `ListView.separated` -- it is a list of PEOPLE, not one
  /// person at a time. An unbounded block means one person who sat a test
  /// forty times produces a row that swallows the pane for everybody
  /// scrolling past them looking for somebody else.
  static const int _visible = 3;

  bool _showAll = false;

  /// One attempt as one line: when it was sat, what it scored, and whether
  /// it passed. `score` may legitimately be null -- `testDetailByUser` sends
  /// it for an attempt that recorded none -- so it says so rather than
  /// printing "null%".
  static String _attemptLine(Map attempt, DisplayFormats formats) {
    final when = formatStamp(attempt['taken_at'], 'Undated', formats: formats);
    final score = attempt['score'] is num
        ? '${(attempt['score'] as num).toInt()}%'
        : 'No score';
    final outcome = attempt['passed'] == true ? 'Passed' : 'Failed';

    return '$when  -  $score  -  $outcome';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final me = widget.session.me!;

    final person = widget.person;

    final name = person['name'] as String? ?? 'Someone';
    final state = person['state'] as String? ?? 'not_started';
    final dueOn = parseCalendarDay(person['due_on'] as String?);

    // A guide's own progress — a real fraction off `last_screen`/
    // `screen_count`, the identical shape `LearnShelves` already draws;
    // never invented for a test, which has no "screens seen".
    final screenCount = person['screen_count'] is num
        ? (person['screen_count'] as num).toInt()
        : null;
    final lastScreen = person['last_screen'] is num
        ? (person['last_screen'] as num).toInt()
        : null;
    final fraction = (screenCount != null && screenCount > 0)
        ? ((lastScreen != null ? lastScreen + 1 : 0) / screenCount).clamp(
            0.0,
            1.0,
          )
        : null;

    // A test's own score. `containsKey` rather than a null check on the
    // VALUE — `testDetailByUser` sends `score: null` for an attempt that
    // failed to record one, which must still say "No score yet" rather
    // than being read as "not a test, say nothing".
    final hasScore = person.containsKey('score');
    final score = person['score'] is num
        ? (person['score'] as num).toInt()
        : null;

    // Every attempt, newest first (owner, 2026-09-09: asked whether this
    // pane shows a person's whole history or only where they stand now, he
    // said "all"). Absent on a GUIDE row, which has no attempts to carry --
    // its history is the progress bar below.
    //
    // Drawn only from the SECOND attempt onward, because A ONE-ITEM LIST IS
    // NOT A SEQUENCE: this block's job is to show change over time, and with
    // one attempt there is no change for the heading to head.
    //
    // Deliberately NOT the reason "the score tag already says it, so the list
    // repeats a fact" -- that argument does not survive its own logic (UI/UX,
    // 2026-09-09). At two attempts the latest is still both the tag and the
    // first line, so that reasoning argues for dropping the current attempt
    // from the list at every count, which is not what this is. From two, the
    // tag is a SUMMARY and the list is the RECORD, and a summary appearing
    // again inside its own detail is context rather than duplication -- the
    // same relation as a count strip above the rows it counts.
    final attempts = person['attempts'] is List
        ? (person['attempts'] as List).whereType<Map>().toList()
        : const <Map>[];

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
          if (attempts.length > 1) ...[
            const SizedBox(height: 8),
            Text('Attempts', style: theme.textTheme.labelSmall),
            const SizedBox(height: 2),
            for (final attempt in _showAll ? attempts : attempts.take(_visible))
              Text(
                _attemptLine(attempt, me.display),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            // Collapsed, it NAMES THE TOTAL, so the reader knows the size of
            // what is hidden before deciding to open it -- the same discipline
            // as an empty sentence describing the box rather than the world.
            // Absent when nothing is hidden: a control claiming to reveal more
            // when there is no more is its own small lie.
            //
            // It TOGGLES rather than expanding one way (UI/UX, 2026-09-09).
            // One-way expansion reintroduces the very thing the cap exists to
            // prevent -- a row swallowing a list of people -- except the reader
            // causes it and then has no way back short of reloading the pane.
            // This is the control finishing its own behaviour, not a second
            // control.
            if (attempts.length > _visible)
              TextButton(
                key: const Key('training.person-toggle-attempts'),
                onPressed: () => setState(() => _showAll = !_showAll),
                child: Text(
                  _showAll
                      ? 'Show fewer'
                      : 'Show all ${attempts.length} attempts',
                ),
              ),
          ],
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
