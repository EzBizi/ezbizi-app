import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/hr.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/load_more.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';

/// The people (HR plan, "Records").
///
/// ── The honest version of itself ─────────────────────────────────
///
/// The plan's Records tab is employees "and everything onboarding collected
/// about them". Onboarding does not exist yet, and neither does the contract
/// template that decides what it collects, so there is nothing collected to
/// show. What there is, is the thing that was missing entirely: somebody's role
/// and whether their account works. Those are what §4 has always described and
/// what no endpoint has ever written.
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
/// It takes no [Session]: everything on this page is the server's answer to
/// "who is here", and none of it is written differently depending on who is
/// looking. The day it needs the organization's own word for a franchise, or a
/// date in the organization's format, is the day to pass one.
class RecordsTab extends StatefulWidget {
  const RecordsTab({super.key, required this.api});

  final ApiClient api;

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
      return 'There is no role you may hand out — §4: nobody gives away a '
          'permission they do not hold themselves.';
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
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
                    'where they are assigned decides what they reach it in.',
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
        trailing: PopupMenuButton<String>(
          itemBuilder: (_) => [
            PopupMenuItem(
              value: 'role',
              enabled: _options.roles.any((role) => role.assignable),
              child: const Text('Change their role'),
            ),
            const PopupMenuDivider(),
            PopupMenuItem(
              value: 'active',
              child: Text(active ? 'Deactivate' : 'Reactivate'),
            ),
          ],
          onSelected: (action) => action == 'role'
              ? _changeRole(person)
              : _setActive(person, !active),
        ),
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
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(response.reason),
          backgroundColor: Theme.of(context).colorScheme.error,
          behavior: SnackBarBehavior.floating,
          width: 420,
        ),
      );
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
