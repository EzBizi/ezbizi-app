import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/hr.dart';
import '../../core/onboarding.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/load_more.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'onboarding_form.dart';

/// The people (HR plan, "Records").
///
/// ── Two things live here ─────────────────────────────────────────
///
/// The plan's Records tab is employees "and everything onboarding collected
/// about them". The first half is the list: somebody's role and whether their
/// account works, which is what §4 has always described.
///
/// The second half is what onboarding collected, and it is not re-drawn here.
/// Opening somebody's record opens their onboarding — the pinned version's
/// questions, the answers, who filled each one in and when, the sensitive ones
/// masked with a reveal that is audited, and the document they signed. That is
/// the same widget the form uses, on purpose: a second renderer for the same
/// answers would be a second place for a sensitive value to be drawn in the
/// clear by accident, and this is the screen where that mistake costs the most.
/// What is editable, and what is masked, stays entirely the server's answer.
///
/// Deactivated people are on the list on purpose. §5's rule is that deactivate
/// is not delete and the record survives, so a list that hid them would be a
/// list of current staff pretending to be a list of staff.
///
/// ── Two things change here, and no third ─────────────────────────
///
/// Their role, and whether the account works. Not their name, phone or date of
/// birth: the plan's one-home rule puts core identity on the person's own
/// profile, where they own it, and an employer editing it here would be the
/// second home. Not their assignment either — moving somebody between places is
/// a real act with its own consequences, and it comes with onboarding.
///
/// There is no delete, and its absence is a decision. Payroll, the audit trail
/// (§10) and every job somebody ever ran point at this row; a record that
/// vanishes takes the readability of all three with it.
///
/// It took no [Session] while the list was all there was, on the stated
/// understanding that the day it needed a date in the organization's format was
/// the day to pass one. That day is this one: an answer carries when it was
/// given, and §6 says who decides how a date is written.
class RecordsTab extends StatefulWidget {
  const RecordsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<RecordsTab> createState() => _RecordsTabState();
}

class _RecordsTabState extends State<RecordsTab> {
  bool _loading = true;
  String? _error;

  /// Which load owns the screen. Changing a role and deactivating somebody both
  /// end in a refresh, and neither hides its own control behind a spinner.
  int _request = 0;

  bool _fetching = false;

  List<Map<String, dynamic>> _people = const [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;

  /// What a role may be changed TO, and which of those this person may hand
  /// out. The same answer the invite form uses, from the same endpoint: §4's
  /// escalation bound is one rule and it should have one source.
  AssignmentOptions _options = AssignmentOptions.none;

  /// Why nobody's role can be changed, when it cannot.
  String? _cannotChangeRole;

  /// Whose record is being read, and which onboarding of theirs. Null for the
  /// list. Replaces the list rather than opening beside it, the same as the
  /// contract designer: a page of collected answers is not a side panel.
  String? _reading;

  /// Opening a record takes a request, so the row that was tapped says so
  /// rather than the whole page going blank.
  String? _opening;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({int page = 1}) async {
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody.
      _loading = page == 1 && _people.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final answers = await Future.wait([
      widget.api.get('/hr/people', {'page': '$page'}),
      widget.api.get('/hr/people/scope-options'),
    ]);

    if (!mounted || request != _request) return;

    final people = answers[0];
    final options = answers[1];

    setState(() {
      _loading = false;
      _fetching = false;

      if (!people.ok) {
        if (page == 1) {
          _error = people.reason;
        } else {
          _pageError = people.reason;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(people.data as List);
      _people = page == 1 ? rows : [..._people, ...rows];

      final meta = people.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _people.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;

      _options = options.ok && options.data is Map
          ? AssignmentOptions.fromJson(
              Map<String, dynamic>.from(options.data as Map),
            )
          : AssignmentOptions.none;

      // The list is still worth reading without the roles; only the one action
      // that needs them goes quiet, and it says why rather than being a menu
      // item that does nothing.
      _cannotChangeRole = _whyNot(options);
    });
  }

  String? _whyNot(ApiResponse options) {
    // Somebody who may edit people but may not invite them. The one endpoint
    // that says which roles are theirs to hand out is the invite form's, so
    // there is no list to draw and the action goes quiet rather than opening an
    // empty panel. Said out loud because a dead menu item with no reason is
    // indistinguishable from a bug.
    if (options.forbidden) {
      return 'Changing somebody’s role needs the list of roles you may hand '
          'out, and that list comes with permission to invite. Your role does '
          'not include it, so roles are read-only here.';
    }

    if (!options.ok) {
      return 'Roles could not be loaded, so nobody’s role can be changed here '
          'right now: ${options.reason}';
    }

    if (!_options.roles.any((role) => role.assignable)) {
      return 'There is no role you may hand out. A role can only be given by '
          'somebody who holds everything in it themselves.';
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_reading case final id?) {
      return OnboardingForm(
        api: widget.api,
        onboardingId: id,
        // The onboarding carries the vocabulary its own questions are written
        // in — see Onboarding.vocabulary — so this tab has no catalogue to
        // fetch and no reason to hold one.
        vocabulary: ContractVocabulary.none,
        formats: _formats,
        backLabel: 'All records',
        onBack: () => setState(() => _reading = null),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Records',
          note: _loading || _error != null
              ? 'Everybody with an account here, including the ones switched '
                    'off.'
              : '$_total ${_total == 1 ? 'person' : 'people'}, including the '
                    'ones switched off. Their role decides what they reach; '
                    'where they are assigned decides what they reach it in. '
                    'Open a record to read what onboarding collected about '
                    'them.',
        ),
        if (_cannotChangeRole != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: ErrorNote(_cannotChangeRole!),
          ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _people.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(32),
                      child: Text(
                        'Nobody here yet. Invite somebody on the Invitations '
                        'tab and they appear the moment they accept.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _people.length + (_page < _lastPage ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => i == _people.length
                        ? LoadMore(
                            shown: _people.length,
                            total: _total,
                            busy: _fetching,
                            error: _pageError,
                            onMore: () => _load(page: _page + 1),
                          )
                        : _row(context, _people[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> person) {
    final roleName = (person['role'] as Map<String, dynamic>?)?['name'];
    final active = person['is_active'] != false;

    // What UserPolicy::update asks for. The screen never asked it, so the one
    // control on this list that writes to somebody else's account was offered
    // to a reader who could only ever be refused.
    final noEdit = widget.session.me?.refusal(
      'hr.edit',
      'change somebody’s account',
    );

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.fromLTRB(20, 8, 8, 8),
        title: Row(
          children: [
            Flexible(
              child: Text(
                person['name'] as String? ?? '',
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            // Said on the row rather than only inside the panel that changes
            // it. "Who has no role" is the question this whole module exists to
            // stop being unanswerable, so it is not something to have to open.
            if (roleName is String)
              Tag(roleName)
            else
              const Tag('No role', warn: true),
            if (!active) const Tag('Deactivated', warn: true),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            [
              person['email'] as String? ?? '',
              _where(person),
            ].where((part) => part.isNotEmpty).join(' · '),
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
        trailing: _opening == person['id']
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : PopupMenuButton<String>(
                // Every row on this list carries the same icon, so the
                // framework default — "Show menu" — is what a screen reader
                // reads for all of them, with no way to tell whose.
                tooltip: 'What to do about ${person['name'] ?? 'this person'}',
                itemBuilder: (context) => [
                  const PopupMenuItem(
                    value: 'record',
                    child: Text('What onboarding collected'),
                  ),
                  const PopupMenuDivider(),
                  // It was greyed with nothing said, and [_cannotChangeRole]
                  // held the sentence the whole time — it was drawn once at the
                  // top of the screen and never beside the control it is about.
                  refusedMenuItem(
                    context,
                    value: 'role',
                    label: 'Change their role',
                    reason: noEdit ?? _cannotChangeRole,
                  ),
                  const PopupMenuDivider(),
                  refusedMenuItem(
                    context,
                    value: 'active',
                    label: active ? 'Deactivate' : 'Reactivate',
                    reason: noEdit,
                  ),
                ],
                onSelected: (action) => switch (action) {
                  'record' => _openRecord(person),
                  'role' => _changeRole(person),
                  _ => _setActive(person, !active),
                },
              ),
      ),
    );
  }

  // ── what onboarding collected ────────────────────────────────────

  /// Their onboardings, and then the one they asked for.
  ///
  /// Asked of the onboardings endpoint filtered to this person rather than of a
  /// record endpoint of its own. The answers belong to an onboarding — they are
  /// answers to the questions of one pinned contract version — so "their
  /// record" IS their onboarding, and a second endpoint returning the same rows
  /// under another name would be a second thing to keep in step with what the
  /// form draws.
  ///
  /// More than one is ordinary rather than exceptional: somebody rehired, or
  /// moved onto a different contract, has two, and both are true. So the list
  /// is offered rather than the newest silently chosen.
  Future<void> _openRecord(Map<String, dynamic> person) async {
    final id = person['id']?.toString();

    if (id == null) return;

    setState(() => _opening = id);

    final response = await widget.api.get('/hr/onboardings', {
      'user_id': id,
      'per_page': '50',
    });

    if (!mounted) return;

    setState(() => _opening = null);

    if (!response.ok) {
      _snack(
        'Their record could not be opened: ${response.reason}',
        isError: true,
      );
      return;
    }

    // Asked of the list filtered to this person. The endpoint used to answer
    // with everything the reader may see, one page at a time, so an older
    // record past the first page was reported as never collected.
    final theirs = OnboardingSummary.listFrom(
      response.data,
    ).where((row) => row.userId == id).toList();

    if (theirs.isEmpty) {
      _snack(
        'Nothing has been collected about ${person['name']} yet. Onboarding is '
        'what asks the questions, and nobody has started one for them.',
      );
      return;
    }

    if (theirs.length == 1) {
      setState(() => _reading = theirs.first.id);
      return;
    }

    final chosen = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        title: person['name'] as String? ?? '',
        subtitle:
            'More than one contract has been run for them. Each one kept the '
            'version of the template it started with, so they do not ask the '
            'same questions.',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final row in theirs)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(row.contractName),
                subtitle: Text(
                  [
                    if (row.version != null) 'version ${row.version}',
                    onboardingStatusLabel(row.status),
                    formatStamp(
                      row.completedAt ?? row.startedAt,
                      '',
                      formats: _formats,
                    ),
                  ].where((part) => part.isNotEmpty).join(' · '),
                ),
                onTap: () => Navigator.of(context).pop(row.id),
              ),
          ],
        ),
      ),
    );

    if (chosen == null || !mounted) return;

    setState(() => _reading = chosen);
  }

  void _snack(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
        behavior: SnackBarBehavior.floating,
        width: 420,
      ),
    );
  }

  /// Every place they hold an assignment.
  ///
  /// A list rather than one value, because §4 allows several: Argentina and
  /// Mexico but not France is the requirement that ruled out the alternative
  /// this platform was nearly built on. Rendering only the first would hide
  /// half of somebody's reach.
  ///
  /// The server has already dropped the ones the reader may not see, so a
  /// company manager in Argentina learns that this person works there without
  /// learning that they also work in France. A short line here can therefore
  /// mean "they work in one place" or "you can see one of their places", and
  /// this end cannot tell the difference — which is the intended shape.
  String _where(Map<String, dynamic> person) {
    final places = (person['assignments'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map((assignment) => assignment['name'] as String? ?? '')
        .where((name) => name.isNotEmpty);

    // Not "no assignment" — an empty string drops out of the line above, and
    // the row is about the person rather than about a gap in their setup.
    return places.join(' · ');
  }

  // ── the two things this tab can change ───────────────────────────

  Future<void> _changeRole(Map<String, dynamic> person) async {
    final current = (person['role'] as Map<String, dynamic>?)?['id']?.toString();

    final chosen = await showSidePanel<String>(
      context,
      builder: (_) => _RolePanel(
        name: person['name'] as String? ?? '',
        roles: _options.roles,
        current: current,
      ),
    );

    if (chosen == null || !mounted || chosen == current) return;

    await _patch(person, {'role_id': chosen});
  }

  Future<void> _setActive(Map<String, dynamic> person, bool active) async {
    if (active) {
      await _patch(person, {'is_active': true});
      return;
    }

    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Deactivate ${person['name']}?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Deactivate'),
          ),
        ],
        child: const Text(
          'They cannot sign in, and their assignments stop granting anything. '
          'The record stays exactly where it is — every job they ran and every '
          'audit entry naming them still has somebody to point at — and '
          'reactivating puts them back.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    await _patch(person, {'is_active': false});
  }

  Future<void> _patch(
    Map<String, dynamic> person,
    Map<String, dynamic> change,
  ) async {
    final response = await widget.api.patch(
      '/hr/people/${person['id']}',
      change,
    );

    if (!mounted) return;

    // The rules this endpoint enforces explain themselves — switching off your
    // own account, a role that reaches further than yours, leaving the
    // organization with no administrator — and reloading the list over a
    // refusal would put the old value back with nothing said.
    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }

    await _load();
  }
}

/// Which role somebody holds.
///
/// One question, so one control and no Save — the choice IS the answer, and a
/// panel with a single list above a Save button charges a click for nothing.
class _RolePanel extends StatelessWidget {
  const _RolePanel({
    required this.name,
    required this.roles,
    required this.current,
  });

  final String name;
  final List<AssignableRole> roles;
  final String? current;

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: name,
      subtitle:
          'A role is organization-wide: changing it changes what they reach '
          'everywhere, not only where they are assigned.',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final role in roles)
            ListTile(
              contentPadding: EdgeInsets.zero,
              enabled: role.assignable,
              selected: role.id == current,
              leading: Icon(
                role.id == current
                    ? Icons.radio_button_checked
                    : Icons.radio_button_unchecked,
                size: 20,
              ),
              title: Text(role.name),
              // Shown and refused rather than hidden. §4: nobody hands out what
              // they do not hold, and a role missing from the list leaves
              // somebody unable to tell that from "we have no such role".
              subtitle: role.assignable
                  ? null
                  : const Text(
                      'Your own role does not hold everything in this one, so '
                      'you cannot give it out.',
                    ),
              onTap: role.assignable
                  ? () => Navigator.of(context).pop(role.id)
                  : null,
            ),
        ],
      ),
    );
  }
}
