import '../widgets/user_avatar.dart';
import '../widgets/list_search.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/permissions.dart';
import '../core/role_inboxes.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/refused.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

/// The organization's roles, and what each of them may do (plan §4).
///
/// ── The one switch, and why it is one switch ─────────────────────
///
/// A module is presented as a tab with an expandable detail:
///
///     Customers   [x]
///       expand
///           View     [x]
///           Create   [x]
///           Edit     [ ]
///           Delete   [ ]
///
/// The tab IS the module's view permission. That is not a shorthand — it is the
/// invariant. A role must never be able to reach "sees the tab but may view
/// nothing", nor "may edit but the tab is hidden", and the way to make a state
/// impossible is to give it no representation. So ticking the tab grants view,
/// unticking it clears everything in that module, and ticking any action inside
/// grants view along with it.
///
/// Nothing about which modules exist, what they are called, or what actions they
/// have is written down here. It arrives from `/roles/catalogue`, which is built
/// from the server's module registry — the same registry that decides what the
/// permissions ARE. One declaration, one source, no Dart copy of it to fall out
/// of step.
///
/// ── The one module whose detail is not just actions ──────────────
///
/// A module may also carry the organization's own INBOXES, nested under it:
///
///     Email       [x]
///       expand
///           View                      [x]
///           Send                      [ ]
///         Inboxes
///           info@a-1chimney.com       [x]
///           billing@everdoors.com     [ ]
///
/// The owner's requirement is that those are two different acts. Ticking Email
/// lets a role open the tab; it grants no mail. So the tab's tick never fills in
/// an inbox, the collapsed row says how many are granted so somebody who expected
/// otherwise can see that they were not, and the module's own "held of total"
/// count stays about actions — a count that mixed the two would be a number that
/// answers neither question.
///
/// Which module this is, and which addresses hang off it, arrives from the same
/// endpoint. This file no more knows that mail exists than it knows Customers
/// does.

/// What each nested grant is called on screen.
///
/// A table rather than a conditional per sentence. There were two kinds and
/// five `kind == 'inspections' ? … : …` expressions; phone lines would have
/// made every one of them a nested ternary, which is the point at which the
/// copy stops being readable and starts being decoded.
///
/// The words differ per kind. The argument every one of them makes does not:
/// opening the tab and using the thing under it are separate grants.
typedef _GrantWords = ({
  /// Heading above the list, e.g. "Phone lines".
  String heading,

  /// Lower-case plural for the running tally, e.g. "phone lines".
  String plural,

  /// Shown on the collapsed row when the organization has none.
  String none,

  /// Shown in the open editor when the organization has none.
  String empty,

  /// The sentence above the ticks.
  String instruction,
});

const Map<String, _GrantWords> _grantWords = <String, _GrantWords>{
  'inboxes': (
    heading: 'Inboxes',
    plural: 'inboxes',
    none: 'No inboxes connected yet.',
    empty:
        'This organization has not connected any shared inboxes yet. They '
        'appear here as soon as somebody does, and can be granted one at a '
        'time.',
    instruction:
        'Opening the tab and reading an inbox are separate grants. Tick each '
        'inbox this role may open.',
  ),
  'inspections': (
    heading: 'Named inspections',
    plural: 'inspections',
    none: 'No named inspections yet.',
    empty:
        'This organization has not created any named inspections yet. They '
        'appear here as soon as somebody does, and can be granted one at a '
        'time.',
    instruction:
        'Opening the tab and starting a named inspection are separate grants. '
        'Tick each inspection this role may start.',
  ),
  'phone_numbers': (
    heading: 'Phone lines',
    plural: 'phone lines',
    none: 'No phone lines yet.',
    empty:
        'This organization has not set up any phone lines yet. They appear '
        'here as soon as somebody adds one, and can be granted one at a time.',
    instruction:
        'Opening the tab and answering a line are separate grants. Tick each '
        'line this role may answer and call from.',
  ),
};

/// Falls back to the inbox wording rather than drawing a blank heading: an
/// unknown kind means the server nested something this build has no words for,
/// and a generic label is a better answer than an empty one.
_GrantWords _wordsFor(String? kind) =>
    _grantWords[kind] ?? _grantWords['inboxes']!;

class RolesScreen extends StatefulWidget {
  const RolesScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<RolesScreen> createState() => _RolesScreenState();
}

class _RolesScreenState extends State<RolesScreen> {
  bool _loading = true;

  /// Filters the role list by name.
  String _listQuery = '';
  String? _error;

  /// Which load owns the screen. Every write here ends in a refresh and the
  /// control that triggered it is not hidden behind a spinner, so two can be in
  /// flight at once — see organization_screen.dart for the failure that taught
  /// this: whichever answer landed last won, whatever was asked for last.
  int _request = 0;

  List<RoleSummary> _roles = const [];
  PermissionCatalogue _catalogue = const PermissionCatalogue([]);

  /// The organization's inboxes, off the same answer as the catalogue.
  InboxCatalogue _inboxes = InboxCatalogue.none;

  /// Which of them each role already holds, off the same answer as the roles.
  Map<String, Set<String>> _held = const {};

  /// The role on the right, by id so it survives the reload every save ends
  /// in. Null picks the first one in the list.
  String? _selectedId;

  /// The role drawn beside it, read-only, or null for none.
  String? _compareId;

  /// On a narrow tab the list and the role page take turns; this says which.
  bool _pageOpen = false;

  /// Bumped on every load and on Discard, so the page is rebuilt from what the
  /// server holds rather than keeping edits that were saved or thrown away.
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Both halves, together.
  ///
  /// The list says what the roles hold; the catalogue says what there is to
  /// hold and what to call it. An editor opened with one and not the other
  /// either shows keys where labels belong or ticks nothing it should — so
  /// neither is rendered until both have arrived.
  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep (see AsyncBody). A refresh
      // after a save keeps the list up and swaps it when the answer lands.
      _loading = _roles.isEmpty;
      _error = null;
    });

    final answers = await Future.wait([
      widget.api.get('/roles'),
      widget.api.get('/roles/catalogue'),
    ]);

    if (!mounted || request != _request) return;

    final roles = answers[0];
    final catalogue = answers[1];

    setState(() {
      _loading = false;

      if (!roles.ok) {
        _error = roles.message;
        return;
      }

      if (!catalogue.ok) {
        _error = catalogue.message;
        return;
      }

      _error = null;
      _roles = RoleSummary.listFrom(roles.data);
      // A fresh page for what the server now holds, so a saved edit is shown
      // as saved rather than kept as a local copy of it.
      _generation++;
      _held = inboxHoldingsFrom(roles.data);
      // The whole body, not `data`: this endpoint answers with `modules` at the
      // top level. PermissionCatalogue.fromResponse reads either.
      _catalogue = PermissionCatalogue.fromResponse(catalogue.body);
      // The same body read a second time, for the half of the tree that is this
      // organization's own rather than the platform's. See role_inboxes.dart for
      // why the two readings are separate.
      _inboxes = InboxCatalogue.fromResponse(catalogue.body);
    });
  }

  void _snack(String message, {bool isError = false}) =>
      showFloatingSnack(context, message, isError: isError);

  Future<void> _create() async {
    final name = await _askForName(
      title: 'New role',
      hint:
          'A role starts with nothing granted. Open it to say what it may do.',
    );

    if (name == null) return;

    final response = await widget.api.post('/roles', {'name': name});

    if (!mounted) return;

    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }

    _snack('Created $name');
    await _load();
  }

  Future<String?> _askForName({
    required String title,
    required String hint,
    String initial = '',
  }) async {
    final value = await showSidePanel<String>(
      context,
      builder: (context) =>
          _NamePanel(title: title, hint: hint, initial: initial),
    );

    return (value == null || value.isEmpty) ? null : value;
  }

  /// Returns null when the server accepted it, or the sentence to show when it
  /// did not — the panel stays open on a refusal, because a permission tree
  /// somebody has just spent a minute on is not something to throw away.
  Future<String?> _save(
    RoleSummary role,
    String name,
    Set<String> permissions,
    Map<String, Set<String>?> nested,
    ({List<String>? ids})? invitable,
    bool? hiddenOnLeads,
  ) async {
    final response = await widget.api.patch('/roles/${role.id}', {
      'name': name,
      'permissions': permissions.toList()..sort(),
      'hidden_on_leads': ?hiddenOnLeads,
      // Only when changed: an unchanged list may name a role this editor
      // could not list themselves, and sending it back would be refused.
      if (invitable != null) 'invitable_roles': invitable.ids,

      // A null entry is omitted rather than sent empty, and the distinction is
      // load-bearing: an empty list asserts "this role holds none of these",
      // which is a revocation. Null means this editor never drew that kind at
      // all — no phone lines exist, say — and has no business asserting
      // anything about it. See [_payloadFor].
      for (final kind in nested.keys)
        if (nested[kind] != null) kind: nested[kind]!.toList()..sort(),
    });

    // Nothing is spliced from the answer here, unlike the settings screen. The
    // save is not the whole truth about the role: the server merges in whatever
    // this person was not entitled to change, so the row that comes back is the
    // one worth going and reading rather than the one we can assemble.

    if (!response.ok) return response.reason;

    if (mounted) _snack('Saved ${role.name}');

    return null;
  }

  Future<void> _delete(RoleSummary role) async {
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Delete ${role.name}?',
        subtitle: role.userCount == 0
            ? 'Nobody holds this role, so nothing changes for anyone.'
            : 'Held by ${_people(role.userCount)}. The server will refuse this '
                  'while anybody still holds it.',
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
            child: const Text('Delete'),
          ),
        ],
        child: const Text(
          'A deleted role cannot be brought back. Its permissions are not held '
          'anywhere else, so anybody who had it would need a new one.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    final response = await widget.api.delete('/roles/${role.id}');

    if (!mounted) return;

    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }

    _snack('Deleted ${role.name}');
    setState(() {
      if (_selectedId == role.id) {
        _selectedId = null;
        _pageOpen = false;
      }
      if (_compareId == role.id) _compareId = null;
    });
    await _load();
  }

  /// Below this width of the tab itself the list and the role take turns.
  ///
  /// Measured from the space the tab is given, not from the window: Settings
  /// draws this inside a shell with its own sidebar, so a 1000pt window can
  /// leave the tab well under the width two panes need.
  static const double _twoPanesFrom = 760;

  RoleSummary? get _selected {
    for (final role in _roles) {
      if (role.id == _selectedId) return role;
    }
    final ordered = [..._builtIn, ..._composed];
    return ordered.isEmpty ? null : ordered.first;
  }

  RoleSummary? get _compare {
    for (final role in _roles) {
      if (role.id == _compareId) return role;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me;
    final noCreate = me?.refusal('roles.create', 'add a role');

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PanelHeader(
            // No title: the Settings chip above already names the tab.
            note: 'Who can open what, and what they can do there.',
            // The one place in the client that ever consulted the permission
            // set — and it HID the button, which leaves somebody looking for
            // "New role" concluding the screen is broken. Shown and refused,
            // like everywhere else now.
            action: Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                // Standard field height, as tall as Leads search (Jose, 2026-10-27).
                SizedBox(
                  width: 240,
                  child: ListSearch(
                    key: const Key('roles-list-search'),
                    hint: 'Find a role',
                    onChanged: (v) => setState(() => _listQuery = v),
                  ),
                ),
                Refused(
                  reason: noCreate,
                  child: FilledButton.icon(
                    key: const ValueKey('tour-roles-new'),
                    onPressed: noCreate == null ? _create : null,
                    icon: const Icon(Icons.add),
                    label: const Text('New role'),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: _roles.isEmpty
                ? const Center(child: Text('There are no roles to show.'))
                : LayoutBuilder(
                    builder: (context, constraints) {
                      final selected = _selected;
                      if (constraints.maxWidth < _twoPanesFrom) {
                        return _pageOpen && selected != null
                            ? _page(selected, narrow: true)
                            : _list(narrow: true);
                      }
                      return Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          SizedBox(width: 240, child: _list(narrow: false)),
                          const VerticalDivider(width: 1),
                          Expanded(
                            child: selected == null
                                ? const SizedBox.shrink()
                                : _page(selected, narrow: false),
                          ),
                        ],
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }

  /// The roles, "Built in" first and then the ones this organization wrote.
  Widget _list({required bool narrow}) {
    final theme = Theme.of(context);
    final selected = _selected;

    Widget heading(String text) => Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: Text(
        text,
        style: theme.textTheme.labelMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );

    Widget row(RoleSummary role) => ListTile(
      key: ValueKey('role-row:${role.id}'),
      dense: true,
      // On a phone nothing is "selected": the list is the whole screen and a
      // highlighted row would be a claim about a page that is not showing.
      selected: !narrow && role.id == selected?.id,
      title: Text(role.name, maxLines: 1, overflow: TextOverflow.ellipsis),
      trailing: Text(
        narrow ? '${role.userCount} ›' : '${role.userCount}',
        style: theme.textTheme.bodySmall,
      ),
      onTap: () => setState(() {
        _selectedId = role.id;
        _pageOpen = true;
      }),
    );

    final q = _listQuery.trim().toLowerCase();
    bool keeps(RoleSummary role) =>
        q.isEmpty || role.name.toLowerCase().contains(q);
    final builtIn = _builtIn.where(keeps).toList();
    final composed = _composed.where(keeps).toList();

    if (builtIn.isEmpty && composed.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Text(
          'No roles match "${_listQuery.trim()}".',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        if (builtIn.isNotEmpty) ...[
          heading('Built in'),
          for (final role in builtIn) row(role),
        ],
        if (composed.isNotEmpty) ...[
          heading('Yours'),
          for (final role in composed) row(role),
        ],
      ],
    );
  }

  Widget _page(RoleSummary role, {required bool narrow}) => _RolePage(
    key: ValueKey('role-page:${role.id}:$_generation'),
    api: widget.api,
    role: role,
    catalogue: _catalogue,
    inboxes: _inboxes,
    heldInboxes: _held[role.id] ?? const {},
    allRoles: _roles,
    narrow: narrow,
    compare: _compare?.id == role.id ? null : _compare,
    onCompare: (id) => setState(() => _compareId = id),
    onBack: narrow ? () => setState(() => _pageOpen = false) : null,
    askForName: (initial) => _askForName(
      title: 'Rename ${role.name}',
      hint: 'The people holding it keep it; only the name changes.',
      initial: initial,
    ),
    onSave: (name, permissions, nested, invitable, hiddenOnLeads) =>
        _save(role, name, permissions, nested, invitable, hiddenOnLeads),
    onSaved: _load,
    onDiscard: () => setState(() => _generation++),
    onDelete: role.mayDelete ? () => _delete(role) : null,
    // The server's sentence for the built-in administrator when it sends one,
    // and the session's own refusal for somebody who may not change roles.
    renameRefusal: !role.editable
        ? (role.lockedReason ??
              'This is the built-in administrator role, so it cannot be '
                  'renamed.')
        : role.mayEdit
        ? null
        : (widget.session.me?.refusal('roles.edit', 'change a role') ??
              'You may not change this role.'),
    deleteRefusal: role.mayDelete
        ? null
        : !role.editable
        ? (role.lockedReason ??
              'This is the built-in administrator role, so it cannot be '
                  'deleted.')
        : (widget.session.me?.refusal('roles.delete', 'delete a role') ??
              'You may not delete this role.'),
  );

  /// The built-in administrator first, then the ones this organization wrote.
  ///
  /// Decided from [RoleSummary.editable], never from the name. An organization
  /// is free to create a role called "Administrators"; matching the word would
  /// pin the wrong row.
  List<RoleSummary> get _builtIn =>
      _roles.where((role) => !role.editable).toList();

  List<RoleSummary> get _composed =>
      _roles.where((role) => role.editable).toList();
}

/// The side panel that asks for a role's name.
///
/// A widget of its own so the text controller lives exactly as long as the
/// panel does. It was created and disposed around the `await`, which disposed
/// it while the panel was still sliding out and reading from it.
class _NamePanel extends StatefulWidget {
  const _NamePanel({
    required this.title,
    required this.hint,
    required this.initial,
  });

  final String title;
  final String hint;
  final String initial;

  @override
  State<_NamePanel> createState() => _NamePanelState();
}

class _NamePanelState extends State<_NamePanel> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.initial,
  );

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    title: widget.title,
    subtitle: widget.hint,
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      FilledButton(
        onPressed: () => Navigator.of(context).pop(_controller.text.trim()),
        child: const Text('Save'),
      ),
    ],
    child: TextField(
      controller: _controller,
      autofocus: true,
      decoration: const InputDecoration(
        labelText: 'Name',
        border: OutlineInputBorder(),
      ),
      onSubmitted: (v) => Navigator.of(context).pop(v.trim()),
    ),
  );
}

String _people(int count) => count == 1 ? '1 person' : '$count people';

class _RoleSection {
  const _RoleSection({
    required this.key,
    required this.label,
    required this.modules,
    this.hint,
  });

  final String key;
  final String label;
  final String? hint;
  final List<PermissionModule> modules;

  /// This section IS a tab: the first module is the destination itself, and
  /// anything after it is drawn inside that tab. The tile carries the name, so
  /// the section needs no heading of its own.
  bool get isTab => modules.first.isOwnDestination;
}

/// One role: a row per module, opened in place to its full list of grants
/// (UI queue 111, option 1). Read-only roles are drawn the same way, dead.
class _RolePage extends StatefulWidget {
  const _RolePage({
    super.key,
    this.api,
    required this.role,
    required this.catalogue,
    required this.inboxes,
    required this.heldInboxes,
    required this.onSave,
    required this.onSaved,
    required this.onDiscard,
    required this.onCompare,
    required this.askForName,
    required this.narrow,
    this.allRoles = const [],
    this.compare,
    this.onBack,
    this.onDelete,
    this.renameRefusal,
    this.deleteRefusal,
  });

  /// Why Rename or Delete is greyed, or null when it may be used.
  final String? renameRefusal;
  final String? deleteRefusal;

  /// For the holders' photos; null in tests that do not need them.
  final ApiClient? api;

  final RoleSummary role;

  /// Every role, for "who they can invite" and "Compare with".
  final List<RoleSummary> allRoles;
  final PermissionCatalogue catalogue;

  /// The organization's own inboxes, by the module they nest under.
  final InboxCatalogue inboxes;

  /// The ones this role already holds.
  final Set<String> heldInboxes;

  /// The role drawn beside this one, read-only.
  final RoleSummary? compare;
  final ValueChanged<String?> onCompare;

  /// The phone layout: one column, a back arrow, rows that say "5 of 9 ›".
  final bool narrow;
  final VoidCallback? onBack;

  /// Null when this person may not delete it; the button is drawn refused.
  final VoidCallback? onDelete;

  /// The side panel that asks for a name, or null when it was cancelled.
  final Future<String?> Function(String initial) askForName;

  /// Answers null on success, or the sentence to show on a refusal.
  ///
  /// [nested] is keyed by `grantKinds`. A value is null when the catalogue held
  /// no module of that kind, so the editor never drew it — see [_payloadFor].
  final Future<String?> Function(
    String name,
    Set<String> permissions,
    Map<String, Set<String>?> nested,
    ({List<String>? ids})? invitable,
    bool? hiddenOnLeads,
  )
  onSave;

  /// After a save the server accepted: reload, which rebuilds this page from
  /// what the server now holds.
  final VoidCallback onSaved;

  /// Throw the edits away, by rebuilding the page from the role as loaded.
  final VoidCallback onDiscard;

  @override
  State<_RolePage> createState() => _RolePageState();
}

class _RolePageState extends State<_RolePage> {
  /// The name, as edited. Changed only by Rename, which saves at once.
  late String _name = widget.role.name;

  /// What the role holds, as edited — ALL of it, including keys this person
  /// cannot change.
  ///
  /// Those are still drawn, ticked and dead, because the honest picture of what
  /// a role can do is not the same as the part of it you happen to be allowed
  /// to touch. They are excluded from [_payload] rather than from the screen:
  /// the server preserves them and would refuse a request that named them.
  late final Set<String> _held = {...widget.role.permissions};

  /// The inboxes this role may open, as edited. Same rule as [_held]: all of
  /// them, including the ones this person cannot move.
  late final Set<String> _inboxes = {...widget.heldInboxes};

  /// Which roles its holders may offer when inviting, or null for "any role
  /// reaching no further than their own" (Jose, 2026-10-01, on Eve's
  /// Suggestion: "select what roles they can offer").
  late Set<String>? _invitable = widget.role.invitableRoles?.toSet();

  /// What changed about [_invitable], or null when nothing did.
  ({List<String>? ids})? get _invitableChange {
    final before = widget.role.invitableRoles?.toSet();
    final now = _invitable;
    final same = before == null
        ? now == null
        : now != null && now.length == before.length && now.containsAll(before);
    return same ? null : (ids: now == null ? null : (now.toList()..sort()));
  }

  /// Left off "X is viewing this lead" (Mia, 2026-10-02).
  late bool _hiddenOnLeads = widget.role.hiddenOnLeads;

  bool _saving = false;
  String? _error;

  /// Two conditions, and they fail for different reasons: the role may be one
  /// nobody can change, or this may be somebody who cannot change roles.
  bool get _writable => widget.role.editable && widget.role.mayEdit;

  /// Why a tick was refused, when it was.
  ///
  /// Shown in the editor rather than as a snack, because the control the person
  /// pressed is still on screen and the sentence is about that control.
  String? _blocked;

  /// What actually goes over the wire.
  ///
  /// Only what this person may grant, on a module the platform has switched on.
  /// Anything else is preserved by the server untouched — and naming it in the
  /// request is a 422 for the whole save, not a key quietly ignored.
  Set<String> get _payload => _held.where(widget.catalogue.mayGrant).toSet();

  /// The inboxes to send, or null to say nothing about inboxes at all.
  ///
  /// Null when no module in this catalogue takes them. The server reads an
  /// absent field as "leave these alone" and an empty list as "this role should
  /// hold none" — and this editor has no business making the second statement
  /// about controls it never drew.
  Set<String>? _payloadFor(String kind) {
    if (!widget.inboxes.hasKind(kind)) return null;
    final ids = <String>{};
    for (final key in widget.inboxes.moduleKeysOf(kind)) {
      for (final item in widget.inboxes.under(key)) {
        if (_inboxes.contains(item.id) && widget.inboxes.mayGrant(item.id)) {
          ids.add(item.id);
        }
      }
    }
    return ids;
  }

  /// What the role holds under this module.
  ///
  /// Matched on the key's prefix, which is how the server derives a module from
  /// a permission too (`ModuleAvailability::isPermissionAvailable`), so an
  /// action this build has never heard of still counts as being inside its own
  /// module rather than being invisible to the tab above it.
  Iterable<String> _keysUnder(String moduleKey) =>
      _held.where((key) => key.startsWith('$moduleKey.'));

  /// The inboxes this role holds under this module.
  ///
  /// Read from the CATALOGUE's list for the module rather than from the held
  /// set, so a grant naming an inbox the organization has since disconnected
  /// counts as nothing here — exactly as the server treats it.
  Iterable<InboxOption> _inboxesUnder(String moduleKey) => widget.inboxes
      .under(moduleKey)
      .where((inbox) => _inboxes.contains(inbox.id));

  /// One tap, one meaning: the tab goes on or it goes off.
  ///
  /// Switching it on grants VIEW and nothing else. The alternative — granting
  /// every action the module declares — hands out Delete to anybody who ticked
  /// a box to make a tab appear, which is not a thing to do by accident. The
  /// detail below is one click away for the rest.
  /// The refused actions, in the catalogue's words rather than as keys.
  /// The label a permission key wears on its own row, from anywhere in the
  /// catalogue — a requirement may name another module's action.
  ///
  /// Falls back to the key rather than to a prettified guess. This screen's
  /// neighbour learned that the hard way: splitting keys on the dot and
  /// upper-casing the front produced headings like "NUMBERING" that nobody
  /// wrote. A key we cannot find is worth showing as the key.
  String _labelForKey(String key) {
    for (final module in widget.catalogue.modules) {
      for (final action in module.actions) {
        if (action.key == key) return action.label;
      }
    }
    return key;
  }

  String _requirementNames(PermissionAction action) =>
      action.requires.map(_labelForKey).join(' and ');

  /// Whether this action is ticked while something it needs is not.
  bool _requirementUnmet(PermissionAction action) =>
      _held.contains(action.key) &&
      action.requires.any((key) => !_held.contains(key));

  /// Layout 2 on a desktop: the requirement rides the label as a muted suffix,
  /// so it is legible BEFORE the tick rather than after the save.
  ///
  /// Nothing is added for an action with no requirement, which is all but one
  /// of them — no icon, no suffix, no reserved space.
  Widget _actionTitle(
    BuildContext context,
    PermissionAction action,
    Color? muted, {
    required bool narrow,
  }) {
    final theme = Theme.of(context);
    if (narrow || action.requires.isEmpty) {
      return Text(action.label, style: TextStyle(color: muted));
    }

    return Text.rich(
      TextSpan(
        text: action.label,
        style: TextStyle(color: muted),
        children: [
          // bodySmall, and both alternatives were measured before settling
          // here. At the label's own size the suffix WRAPS at this width (the
          // row goes 48 -> 56, a second line). At bodySmall it stays on one
          // line and costs 4pt of line height (48 -> 52).
          //
          // 4pt on one row is not the two-heights defect this file has met
          // before: that was seven rows ALTERNATING, a broken rhythm you can
          // see. Exactly one action in the product declares a requirement, so
          // exactly one row is 4pt taller, and nothing reads as uneven. The
          // failure worth guarding is the wrap, and the test guards that.
          TextSpan(
            text: '  ·  needs ${_requirementNames(action)}',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }

  /// The line under the row.
  ///
  /// On a phone (layout 6) it carries the requirement at rest, because a phone
  /// has vertical room and no hover: a dependency only revealed by touching
  /// something is one nobody reads. On a desktop the suffix has already said
  /// it, so this appears ONLY when the requirement is unmet.
  ///
  /// It never ticks the other box. The server refuses rather than implying —
  /// `withImpliedView` adds a missing `.view` because that is the least a
  /// module can grant, while the larger disclosure would hand out more than
  /// the administrator ticked. A screen that helpfully ticked it would undo
  /// that decision quietly.
  Widget? _requirementLine(
    BuildContext context,
    PermissionAction action, {
    required bool narrow,
  }) {
    if (action.requires.isEmpty) return null;

    final theme = Theme.of(context);
    final unmet = _requirementUnmet(action);
    if (!narrow && !unmet) return null;

    final names = _requirementNames(action);
    return Text(
      unmet ? 'Needs $names — not ticked' : 'Needs $names',
      style: theme.textTheme.bodySmall?.copyWith(
        color: unmet
            ? theme.colorScheme.error
            : theme.colorScheme.onSurfaceVariant,
        fontWeight: unmet ? FontWeight.w600 : null,
      ),
    );
  }

  String _actionNames(PermissionModule module, List<String> keys) {
    final labels = <String>[];

    for (final action in module.actions) {
      if (keys.contains(action.key)) labels.add(action.label.toLowerCase());
    }

    if (labels.isEmpty) return 'permissions';
    if (labels.length == 1) return labels.first;

    return '${labels.sublist(0, labels.length - 1).join(', ')} and ${labels.last}';
  }

  void _toggleModule(PermissionModule module) {
    final viewKey = module.viewKey;
    if (viewKey == null) return;

    setState(() {
      if (_keysUnder(module.key).isNotEmpty ||
          _inboxesUnder(module.key).isNotEmpty) {
        /*
         * Refused rather than half-done, when some of it is not this person's
         * to remove.
         *
         * This cleared only the keys the caller may grant, which produced the
         * one state this whole design exists to make unrepresentable. A role
         * holding customers.view and customers.delete, edited by somebody
         * holding view but not delete: the tick cleared view, delete stayed, and
         * the tab read "off" with an action still under it — "1 of 4", untick
         * ticked. On save the server then preserved delete and re-implied view,
         * so the tab came back and the click had done nothing, silently.
         *
         * Neither half of that is acceptable, and there is no third option that
         * is: this person genuinely cannot take delete away. So the control says
         * so instead of pretending. The keys they DO own are left alone as well,
         * because clearing half a module is not what anybody meant by unticking
         * the tab.
         */
        final locked = _keysUnder(
          module.key,
        ).where((key) => !widget.catalogue.mayGrant(key)).toList();

        if (locked.isNotEmpty) {
          _blocked =
              'This role holds ${_actionNames(module, locked)} under '
              '${module.label}, which you do not hold yourself — so you cannot '
              'remove it. Ask somebody who does.';
          return;
        }

        /*
         * The same refusal for an inbox, and it has to be here rather than left
         * to the save.
         *
         * Switching the tab off takes the inboxes with it — a grant behind a tab
         * the role cannot open opens nothing, and the server refuses that state
         * outright. So an inbox this person cannot open is an inbox they cannot
         * switch the tab off over: doing it halfway would leave a save the
         * server rejects, and doing it silently would revoke mail access nobody
         * chose to revoke.
         */
        final lockedInboxes = _inboxesUnder(
          module.key,
        ).where((inbox) => !inbox.grantable).toList();

        if (lockedInboxes.isNotEmpty) {
          _blocked =
              'This role can open ${_inboxNames(lockedInboxes)}, which you '
              'cannot open yourself — so you cannot switch ${module.label} off '
              'over it. Ask somebody who can.';
          return;
        }

        _blocked = null;
        _held.removeWhere((key) => key.startsWith('${module.key}.'));
        // With the tab gone the inboxes under it grant nothing, so they go too
        // rather than being left as rows the server would refuse.
        _inboxes.removeAll(widget.inboxes.under(module.key).map((i) => i.id));
        return;
      }

      _blocked = null;
      _held.add(viewKey);
    });
  }

  /// The refused inboxes, as somebody would name them out loud.
  String _inboxNames(List<InboxOption> inboxes) {
    final names = inboxes.map((inbox) => inbox.address).toList();

    if (names.length == 1) return names.first;

    return '${names.sublist(0, names.length - 1).join(', ')} and ${names.last}';
  }

  /// One inbox, on or off.
  ///
  /// Granting one grants the tab it lives on, for the same reason granting an
  /// action does: a role that may read info@ and cannot open the module has been
  /// given nothing, and the server refuses to store it. The reverse is
  /// deliberately NOT true — see the class comment. Switching Email on grants no
  /// mail, because "I ticked Email so they can read everything" is exactly the
  /// assumption the owner asked to make impossible.
  void _toggleInbox(PermissionModule module, InboxOption inbox) {
    setState(() {
      _blocked = null;

      if (_inboxes.contains(inbox.id)) {
        _inboxes.remove(inbox.id);
        return;
      }

      _inboxes.add(inbox.id);

      final viewKey = module.viewKey;
      if (viewKey != null) _held.add(viewKey);
    });
  }

  void _toggleAction(PermissionModule module, PermissionAction action) {
    final viewKey = module.viewKey;

    setState(() {
      if (_held.contains(action.key)) {
        // Taking away the view permission takes the tab away, and a tab that is
        // not there cannot have live actions behind it.
        if (action.key == viewKey) {
          _held.removeWhere(
            (key) =>
                key.startsWith('${module.key}.') &&
                widget.catalogue.mayGrant(key),
          );

          // And the inboxes with them: behind a tab this role cannot open, an
          // inbox grant opens nothing and the server refuses to store it.
          //
          // Only the ones this person may move, which is what the line above
          // does with permission keys and is the same compromise: an inbox they
          // cannot open is left where it is, and the save comes back with a
          // sentence saying to switch the tab on again. The tab's own checkbox
          // refuses up front instead — it can, because it is one gesture about
          // the whole module rather than about one row inside it.
          _inboxes.removeAll(
            widget.inboxes
                .under(module.key)
                .where((inbox) => inbox.grantable)
                .map((inbox) => inbox.id),
          );
        } else {
          _held.remove(action.key);
        }

        return;
      }

      _held.add(action.key);

      // Granting anything grants the tab it lives on. "May edit a customer but
      // cannot open Customers" is the other impossible state.
      if (viewKey != null) _held.add(viewKey);
    });
  }

  /// Which roles this role's holders may give somebody they invite.
  Widget _whoTheyCanInvite(ThemeData theme) {
    final chosen = _invitable;

    return Column(
      key: const ValueKey('role-invitable'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Who they can invite', style: theme.textTheme.titleSmall),
        const SizedBox(height: 6),
        SegmentedButton<bool>(
          segments: const [
            ButtonSegment(value: false, label: Text('Roles within their own')),
            ButtonSegment(value: true, label: Text('Only roles chosen here')),
          ],
          selected: {chosen != null},
          onSelectionChanged: _writable
              ? (value) => setState(
                  () =>
                      _invitable = value.first ? (chosen ?? <String>{}) : null,
                )
              : null,
        ),
        const SizedBox(height: 6),
        Text(
          chosen == null
              ? 'People with this role can invite somebody as any role that '
                    'reaches no further than their own.'
              : 'People with this role can invite somebody only as the roles '
                    'ticked below.',
          style: theme.textTheme.bodySmall,
        ),
        if (chosen != null)
          for (final role in widget.allRoles)
            CheckboxListTile(
              key: ValueKey('role-invitable-${role.id}'),
              dense: true,
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              title: Text(role.name),
              value: chosen.contains(role.id),
              onChanged: _writable
                  ? (on) => setState(() {
                      final next = {...chosen};
                      on == true ? next.add(role.id) : next.remove(role.id);
                      _invitable = next;
                    })
                  : null,
            ),
      ],
    );
  }

  Future<void> _save() async {
    final name = _name.trim();

    if (name.isEmpty) {
      setState(() => _error = 'A role needs a name.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    final failure = await widget.onSave(
      name,
      _payload,
      {for (final kind in grantKinds) kind: _payloadFor(kind)},
      _invitableChange,
      _hiddenOnLeads == widget.role.hiddenOnLeads ? null : _hiddenOnLeads,
    );

    if (!mounted) return;

    if (failure != null) {
      setState(() {
        _saving = false;
        _error = failure;
      });
      return;
    }

    setState(() => _saving = false);
    widget.onSaved();
  }

  /// Which modules are open to their full list.
  final Set<String> _expanded = {};

  /// What "Find a permission" holds, lower-cased.
  String _query = '';

  /// Whether anything differs from the role as it was loaded.
  bool get _dirty =>
      _name != widget.role.name ||
      !setEquals(_held, widget.role.permissions) ||
      !setEquals(_inboxes, widget.heldInboxes) ||
      _invitableChange != null ||
      _hiddenOnLeads != widget.role.hiddenOnLeads;

  /// How many modules this role can open, as edited.
  int get _modulesOpen => widget.catalogue.modules
      .where((m) => m.viewKey != null && _held.contains(m.viewKey))
      .length;

  /// Inviting somebody is `hr.create` (InvitationPolicy::create), so "who
  /// they can invite" is summarised on the row that grants it and nowhere
  /// else. The choice itself is made under the grid, because it is about the
  /// role rather than about any one module.
  static const String _inviteKey = 'hr.create';

  static const double _openWidth = 56;
  static const double _tabsWidth = 72;
  static const double _compareWidth = 96;
  static const double _chevronWidth = 32;

  bool _labelMatches(PermissionModule module) =>
      _query.isEmpty || module.label.toLowerCase().contains(_query);

  bool _actionMatches(PermissionAction action) =>
      action.label.toLowerCase().contains(_query);

  bool _inboxMatches(InboxOption inbox) =>
      inbox.heading.toLowerCase().contains(_query) ||
      inbox.address.toLowerCase().contains(_query);

  /// Whether "Find a permission" leaves this row on screen: its own name, a
  /// grant inside it, or an inbox under it.
  bool _matches(PermissionModule module) =>
      _labelMatches(module) ||
      module.actions.any(_actionMatches) ||
      widget.inboxes.under(module.key).any(_inboxMatches);

  /// Open when somebody opened it, or when the search found something INSIDE
  /// it — a match nobody can see without a click is not much of a match.
  bool _isExpanded(PermissionModule module) =>
      _expanded.contains(module.key) ||
      (_query.isNotEmpty && !_labelMatches(module));

  /// What they can do there, not counting the tabs: the column of that name.
  Iterable<PermissionAction> _doActions(PermissionModule module) =>
      module.actions.where((a) => !a.tab);

  /// The box in "What they can do", for this role or the one beside it.
  ///
  /// Three states, and the middle one is the point:
  ///
  ///   false — the module does not open, and nothing inside it is granted
  ///   null  — it opens, and some of what can be done there is withheld
  ///   true  — it opens and everything that can be done there is granted
  ///
  /// About the ACTIONS, and deliberately not about the inboxes. Folding those in
  /// would mean a full box could not be reached without granting every address
  /// the organization has, and the one state this control must never suggest is
  /// that switching Email on came with the mail. The inbox count is its own line
  /// under the module's name — see [_inboxTally].
  bool? _canDoState(PermissionModule module, Set<String> held) {
    final viewKey = module.viewKey;

    if (viewKey == null || !held.contains(viewKey)) return false;

    return _doActions(module).every((a) => held.contains(a.key)) ? true : null;
  }

  String _tabsSummary(PermissionModule module) {
    final tabs = module.actions.where((a) => a.tab).toList();
    if (tabs.isEmpty || _canDoState(module, _held) == false) return '—';
    final held = tabs.where((a) => _held.contains(a.key)).length;
    return held == tabs.length ? 'all' : '$held of ${tabs.length}';
  }

  String _canDoSummary(PermissionModule module) {
    final actions = _doActions(module).toList();
    final state = _canDoState(module, _held);
    if (state == false || actions.isEmpty) return '—';
    if (state == true) return 'all ${actions.length}';
    final held = actions.where((a) => _held.contains(a.key)).toList();
    final names = held.take(3).map((a) => a.label.toLowerCase()).join(', ');
    return '${held.length} of ${actions.length} · $names';
  }

  /// The phone's one number per row: "off", "all", or "5 of 9".
  String _phoneSummary(PermissionModule module) {
    final state = _canDoState(module, _held);
    if (state == false) return 'off';
    if (state == true) return 'all';
    final actions = _doActions(module).toList();
    final held = actions.where((a) => _held.contains(a.key)).length;
    return '$held of ${actions.length}';
  }

  String _inviteSummary(PermissionModule module) {
    if (!module.actions.any((a) => a.key == _inviteKey) ||
        !_held.contains(_inviteKey)) {
      return '—';
    }
    final chosen = _invitable;
    if (chosen == null) return 'Roles within their own';
    if (chosen.isEmpty) return 'Nobody';
    return [
      for (final role in widget.allRoles)
        if (chosen.contains(role.id)) role.name,
    ].join(', ');
  }

  Future<void> _rename() async {
    final name = await widget.askForName(_name);
    if (name == null || !mounted) return;
    setState(() => _name = name);
    await _save();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final narrow = widget.narrow;
    final side = narrow ? 16.0 : 24.0;

    return SingleChildScrollView(
      key: const ValueKey('role-page'),
      padding: EdgeInsets.fromLTRB(side, narrow ? 4 : 16, side, 32),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _header(theme),
          const SizedBox(height: 12),
          if (_writable) ...[_saveBar(theme), const SizedBox(height: 12)],
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 12),
          ],
          if (!widget.role.editable) _lockedNote(theme),
          if (widget.role.editable && !widget.role.mayEdit)
            _note(
              theme,
              Icons.visibility_outlined,
              'You can see what this role holds, but you may not change it.',
            ),

          // A refused tick. Cleared by the next successful one, so it describes
          // the last thing that happened rather than lingering as a warning
          // about something already dealt with.
          if (_blocked != null) ...[
            _note(theme, Icons.lock_outline, _blocked!),
            const SizedBox(height: 4),
          ],

          // Which state means what, said once, above the thing it describes. A
          // dash in a box is not self-explanatory and this screen has a lot of
          // them.
          Text(
            'Ticking Open lets this role open a module — the same tabs, in the '
            'same order, as the sidebar. A full box means everything they can '
            'do there is granted, a dash means only some is. '
            '${narrow ? 'Tap' : 'Click'} a row for its full list. Settings at '
            'the bottom of the sidebar is not a work tab; Organization lives '
            'inside it.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),

          if (widget.catalogue.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                'The server declared no modules, so there is nothing to grant.',
                style: theme.textTheme.bodyMedium,
              ),
            )
          else
            _grid(theme),

          const SizedBox(height: 24),
          _whoTheyCanInvite(theme),
          const SizedBox(height: 16),
          SwitchListTile(
            key: const ValueKey('role-seen-on-leads'),
            contentPadding: EdgeInsets.zero,
            title: const Text('Seen on leads'),
            subtitle: Text(
              _hiddenOnLeads
                  ? 'Hidden: when somebody with this role opens a lead, nobody '
                        'else is told. For people who inspect leads rather '
                        'than answer them.'
                  : 'When somebody with this role opens a lead, the others '
                        'see their name on it.',
            ),
            value: !_hiddenOnLeads,
            onChanged: _writable
                ? (seen) => setState(() => _hiddenOnLeads = !seen)
                : null,
          ),
          const SizedBox(height: 16),
          _whoHasIt(theme),
        ],
      ),
    );
  }

  /// Name, counts, search, Compare with, Rename and Delete.
  Widget _header(ThemeData theme) {
    final role = widget.role;
    final narrow = widget.narrow;
    final modules = _modulesOpen;

    final title = Text(
      role.name,
      key: const ValueKey('role-title'),
      style: theme.textTheme.titleLarge,
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
    );
    final counts = Text(
      '${_people(role.userCount)} · '
      '${modules == 1 ? '1 module' : '$modules modules'}',
      key: const ValueKey('role-counts'),
      style: theme.textTheme.bodySmall,
    );

    final search = SizedBox(
      width: narrow ? double.infinity : 220,
      child: ListSearch(
        key: const ValueKey('role-search'),
        hint: 'Find a permission',
        onChanged: (value) =>
            setState(() => _query = value.trim().toLowerCase()),
      ),
    );

    final compare = SizedBox(
      width: 200,
      child: SearchPickerField<String>(
        key: const ValueKey('role-compare'),
        value: widget.compare?.id,
        label: 'Compare with',
        isDense: true,
        allowClear: true,
        border: const OutlineInputBorder(),
        options: [
          for (final other in widget.allRoles)
            if (other.id != role.id)
              SearchOption(
                value: other.id,
                label: other.name,
                detail: _people(other.userCount),
              ),
        ],
        onChanged: widget.onCompare,
      ),
    );

    // Greyed with the reason rather than hidden, the same as New role: a
    // missing button reads as a broken screen.
    final rename = Refused(
      reason: widget.renameRefusal,
      child: OutlinedButton(
        key: const ValueKey('role-rename'),
        onPressed: _writable && !_saving ? _rename : null,
        child: const Text('Rename'),
      ),
    );
    final delete = Refused(
      reason: widget.deleteRefusal,
      child: OutlinedButton(
        key: const ValueKey('role-delete'),
        style: OutlinedButton.styleFrom(
          foregroundColor: theme.colorScheme.error,
        ),
        onPressed: widget.onDelete,
        child: const Text('Delete'),
      ),
    );

    if (narrow) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              IconButton(
                key: const ValueKey('role-back'),
                tooltip: 'All roles',
                icon: const Icon(Icons.arrow_back),
                onPressed: widget.onBack,
              ),
              Expanded(child: title),
            ],
          ),
          counts,
          const SizedBox(height: 8),
          search,
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [compare, rename, delete],
          ),
        ],
      );
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Padding(
          padding: const EdgeInsets.only(right: 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [title, counts],
          ),
        ),
        search,
        compare,
        rename,
        delete,
      ],
    );
  }

  Widget _saveBar(ThemeData theme) => Row(
    children: [
      Expanded(
        child: Text(
          _dirty ? 'Unsaved changes.' : 'No changes yet.',
          style: theme.textTheme.bodySmall,
        ),
      ),
      if (_dirty)
        TextButton(
          onPressed: _saving ? null : widget.onDiscard,
          child: const Text('Discard'),
        ),
      const SizedBox(width: 8),
      FilledButton(
        onPressed: _saving ? null : _save,
        child: Text(_saving ? 'Saving…' : 'Save'),
      ),
    ],
  );

  /// The grid: a row per module, in the sidebar's order, with Organization
  /// and Settings under their own headings.
  Widget _grid(ThemeData theme) {
    final rows = <Widget>[];

    for (final section in _sections(widget.catalogue.modules)) {
      final visible = section.modules.where(_matches).toList();
      if (visible.isEmpty) continue;

      if (!section.isTab) {
        rows.add(
          Padding(
            padding: const EdgeInsets.only(top: 16, bottom: 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(section.label, style: theme.textTheme.titleSmall),
                if (section.hint != null) ...[
                  const SizedBox(height: 4),
                  Text(section.hint!, style: theme.textTheme.bodySmall),
                ],
              ],
            ),
          ),
        );
      }

      for (final module in visible) {
        // Indented under the tab it is drawn inside, which is the only thing
        // on this screen that says so.
        rows.add(_moduleRow(module, theme, indent: module.parent != null));
      }
    }

    return Column(
      key: const ValueKey('roles-grid'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!widget.narrow) _gridHeader(theme),
        if (rows.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Text(
              'No module or permission matches that.',
              style: theme.textTheme.bodyMedium,
            ),
          )
        else
          ...rows,
      ],
    );
  }

  Widget _gridHeader(ThemeData theme) {
    final style = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      letterSpacing: 0.6,
    );
    Widget cell(String text, {Key? key}) => Text(
      text,
      key: key,
      style: style,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Row(
        children: [
          Expanded(flex: 3, child: cell('MODULE')),
          SizedBox(
            width: _openWidth,
            child: Center(child: cell('OPEN')),
          ),
          SizedBox(width: _tabsWidth, child: cell('TABS')),
          Expanded(flex: 4, child: cell('WHAT THEY CAN DO')),
          Expanded(flex: 2, child: cell('INVITE')),
          if (widget.compare case final other?)
            SizedBox(
              width: _compareWidth,
              child: Center(
                child: cell(other.name, key: const ValueKey('compare-heading')),
              ),
            ),
          const SizedBox(width: _chevronWidth),
        ],
      ),
    );
  }

  /// A box that says all, some or none — drawn, never pressed.
  Widget _triBox(ThemeData theme, bool? state, {required Key key}) => Icon(
    state == true
        ? Icons.check_box
        : state == null
        ? Icons.indeterminate_check_box
        : Icons.check_box_outline_blank,
    key: key,
    size: 20,
    color: state == false
        ? theme.colorScheme.outline
        : theme.colorScheme.primary,
    semanticLabel: state == true
        ? 'All granted'
        : state == null
        ? 'Some granted'
        : 'None granted',
  );

  /// One module: its row in the grid, and under it, once opened, the full
  /// list of what can be granted there.
  Widget _moduleRow(
    PermissionModule module,
    ThemeData theme, {
    required bool indent,
  }) {
    // Two separate reasons a row can be dead, and they are not the same
    // sentence. One is about this person and this role; the other is about the
    // whole organization and is nothing an administrator here can undo.
    final live = _writable && module.available;

    // A module the platform has switched off is SHOWN, greyed, with the reason
    // (§13). Removing it from the list would leave somebody hunting for a tab
    // that used to be there and concluding the editor is broken — and the kill
    // switch exists to be used mid-incident, when that guess costs the most.
    final muted = module.available
        ? null
        : theme.colorScheme.onSurface.withValues(alpha: 0.45);

    final expanded = _isExpanded(module);
    final viewKey = module.viewKey;
    final compare = widget.compare;
    final small = theme.textTheme.bodySmall;

    // Open IS the module's view permission — the one switch. Ticking it grants
    // view and nothing else; unticking it clears the module, or refuses.
    final open = Checkbox(
      value: viewKey != null && _held.contains(viewKey),
      onChanged: live && viewKey != null && widget.catalogue.mayGrant(viewKey)
          ? (_) => _toggleModule(module)
          : null,
    );

    final subtitle = module.available
        ? _inboxTally(module, theme)
        : Text(
            module.unavailableReason ??
                'Switched off for your organization by EzBizi.',
            style: small?.copyWith(color: theme.colorScheme.error),
          );

    final name = Padding(
      padding: EdgeInsets.only(left: indent ? 20 : 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            module.label,
            key: ValueKey('module-name:${module.key}'),
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
              color: muted,
            ),
          ),
          ?subtitle,
        ],
      ),
    );

    final Widget row;
    if (widget.narrow) {
      row = Row(
        children: [
          open,
          Expanded(child: name),
          if (compare != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: _triBox(
                theme,
                _canDoState(module, compare.permissions),
                key: ValueKey('compare:${module.key}'),
              ),
            ),
          Text(
            '${_phoneSummary(module)} ›',
            key: ValueKey('module-summary:${module.key}'),
            style: small?.copyWith(color: muted),
          ),
        ],
      );
    } else {
      row = Row(
        children: [
          Expanded(flex: 3, child: name),
          SizedBox(
            width: _openWidth,
            child: Center(child: open),
          ),
          SizedBox(
            width: _tabsWidth,
            child: Text(
              _tabsSummary(module),
              key: ValueKey('tabs:${module.key}'),
              style: small?.copyWith(color: muted),
            ),
          ),
          Expanded(
            flex: 4,
            child: Row(
              children: [
                _triBox(
                  theme,
                  _canDoState(module, _held),
                  key: ValueKey('can-do:${module.key}'),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    _canDoSummary(module),
                    key: ValueKey('can-do-text:${module.key}'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: small?.copyWith(color: muted),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            flex: 2,
            child: Text(
              _inviteSummary(module),
              key: ValueKey('invite:${module.key}'),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: small?.copyWith(color: muted),
            ),
          ),
          if (compare != null)
            SizedBox(
              width: _compareWidth,
              child: Center(
                child: _triBox(
                  theme,
                  _canDoState(module, compare.permissions),
                  key: ValueKey('compare:${module.key}'),
                ),
              ),
            ),
          SizedBox(
            width: _chevronWidth,
            child: Icon(
              expanded ? Icons.expand_less : Icons.expand_more,
              size: 20,
            ),
          ),
        ],
      );
    }

    return Container(
      key: ValueKey('module:${module.key}'),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            onTap: () => setState(() {
              if (!_expanded.remove(module.key)) _expanded.add(module.key);
            }),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: row,
            ),
          ),
          if (expanded)
            Padding(
              padding: EdgeInsets.only(
                left: widget.narrow ? 8 : 40 + (indent ? 20 : 0),
                right: 8,
                bottom: 8,
              ),
              child: _detail(module, theme, live: live, muted: muted),
            ),
        ],
      ),
    );
  }

  /// A module opened in place: the same checkbox lists the side panel had.
  Widget _detail(
    PermissionModule module,
    ThemeData theme, {
    required bool live,
    required Color? muted,
  }) {
    // Searching for "export" inside Customers shows the Export row, not the
    // other eleven; searching for "Customers" shows all of them.
    final everything = _labelMatches(module);
    bool shown(PermissionAction action) => everything || _actionMatches(action);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // One width decision for every row, taken from the space the rows
        // actually have rather than from the window: layout 2 rides the
        // requirement on the label, layout 6 puts it underneath, and the two
        // must not both appear because two slots measured separately.
        //
        // 400, not the 600 used elsewhere, and it is MEASURED rather than
        // conventional: on a phone a row is about 330pt, in the grid on a
        // desktop it is several hundred more.
        LayoutBuilder(
          builder: (context, constraints) => Column(
            // Headings to the left, under the module (Jose, 2026-10-20: the
            // support text sat in the middle).
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // TABS FIRST, UNDER THEIR OWN HEADING, and then what may be done.
              //
              // Added 2026-09-17 with per-tab grants. Human Resources went from
              // four checkboxes to eleven, and a flat list mixing "Work Hours"
              // with "Edit" asks the reader to work out which of two questions
              // each row is answering. The headings answer it once.
              //
              // Both headings are conditional on tabs EXISTING: a module with
              // none draws exactly what it drew yesterday, with no lone "What
              // they can do" caption over the same four rows it always had.
              if (module.actions.any((a) => a.tab)) ...[
                _grantHeading(
                  theme,
                  'Tabs',
                  'Which tabs inside ${module.label} this role may open.',
                ),
                ..._tabRows(
                  module,
                  shown,
                  theme,
                  (action) => _actionRow(
                    context,
                    module,
                    action,
                    muted,
                    live,
                    constraints,
                  ),
                ),
                _grantHeading(
                  theme,
                  'What they can do',
                  'Applies wherever ${module.label} allows it, in the tabs '
                      'above.',
                ),
              ],
              for (final action in module.actions.where(
                (a) => !a.tab && shown(a),
              ))
                _actionRow(context, module, action, muted, live, constraints),
            ],
          ),
        ),

        ..._inboxRows(module, theme, live: live, muted: muted),
      ],
    );
  }

  /// Tab rows nested the way the app draws them (Jose, 2026-11-09): a tab
  /// inside another sits indented under it, and a group with no tick of its
  /// own (Leads › Calls) gets its name as a small heading.
  List<Widget> _tabRows(
    PermissionModule module,
    bool Function(PermissionAction) shown,
    ThemeData theme,
    Widget Function(PermissionAction) row,
  ) {
    final tabs = module.actions.where((a) => a.tab).toList();
    final labels = {for (final a in tabs) a.label};
    final rows = <Widget>[];
    String? group;

    for (final action in tabs.where(shown)) {
      final within = action.within;
      if (within != null && within != group && !labels.contains(within)) {
        rows.add(
          Padding(
            key: ValueKey('tab-group:${module.key}:$within'),
            padding: const EdgeInsets.fromLTRB(24, 8, 0, 2),
            child: Text(within, style: theme.textTheme.labelMedium),
          ),
        );
      }
      group = within;
      rows.add(
        within == null
            ? row(action)
            : Padding(
                key: ValueKey('tab-nested:${action.key}'),
                padding: EdgeInsets.only(
                  left: labels.contains(within) ? 24 : 40,
                ),
                child: row(action),
              ),
      );
    }

    return rows;
  }

  /// "Who has it": a few faces and names, and "+N" for the rest.
  Widget _whoHasIt(ThemeData theme) {
    final role = widget.role;
    final people = role.people;
    final shown = (people ?? const <({String id, String name})>[])
        .take(6)
        .toList();
    final rest = role.userCount - shown.length;
    final scheme = theme.colorScheme;

    return Column(
      key: const ValueKey('role-people'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Who has it', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        if (role.userCount == 0 && shown.isEmpty)
          Text('Nobody holds this role yet.', style: theme.textTheme.bodySmall)
        // An older server sends no names; the count is still true.
        else if (people == null)
          Text(
            role.userCount == 1
                ? '1 person holds this role.'
                : '${role.userCount} people hold this role.',
            style: theme.textTheme.bodySmall,
          )
        else
          Wrap(
            spacing: 12,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              for (final person in shown)
                Row(
                  key: ValueKey('role-person:${person.id}'),
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (widget.api case final api?)
                      UserAvatar(
                        api: api,
                        userId: person.id,
                        name: person.name,
                        radius: 12,
                      )
                    else
                      CircleAvatar(
                        radius: 12,
                        backgroundColor: scheme.primaryContainer,
                        child: Text(
                          initials(person.name),
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: scheme.onPrimaryContainer,
                          ),
                        ),
                      ),
                    const SizedBox(width: 6),
                    Text(person.name, style: theme.textTheme.bodySmall),
                  ],
                ),
              if (rest > 0)
                Text(
                  '+$rest',
                  key: const ValueKey('role-people-more'),
                  style: theme.textTheme.bodySmall,
                ),
            ],
          ),
      ],
    );
  }

  Widget _lockedNote(ThemeData theme) => _note(
    theme,
    Icons.lock_outline,
    // The server's sentence when it sends one. The fallback is copy about a
    // decision the owner made, not a rule this end is deciding: which role is
    // locked was answered by `editable`, above, and never by its name.
    widget.role.lockedReason ??
        'This is the organization’s built-in administrator role. It cannot be '
            'renamed or have permissions taken away — a role able to remove its '
            'own powers can lock an organization out of its own account.',
  );

  Widget _note(ThemeData theme, IconData icon, String text) => Padding(
    padding: const EdgeInsets.only(bottom: 20),
    child: Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 16, color: theme.colorScheme.onSurfaceVariant),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: theme.textTheme.bodySmall)),
        ],
      ),
    ),
  );

  /// Tabs first, in sidebar order, then Organization, then Settings.
  ///
  /// A module drawn inside another (Leads, Reviews and Business Profile inside
  /// Marketing) is listed under its parent's tab rather than as a tab of its
  /// own, so the grants are laid out the way the app they grant is. It stays
  /// one switch per module: nesting a row does not tie the two together, and
  /// the server would refuse the implied grant if it did.
  List<_RoleSection> _sections(List<PermissionModule> modules) {
    final tabs = <PermissionModule>[];
    final nested = <String, List<PermissionModule>>{};
    final children = <String, List<PermissionModule>>{};

    for (final module in modules) {
      if (module.parent case final parent?) {
        (children[parent] ??= []).add(module);
        continue;
      }
      if (module.isSidebarTab) {
        tabs.add(module);
        continue;
      }
      (nested[module.groupKey] ??= []).add(module);
    }

    // A child whose parent is not in the tree, drawn as a tab of its own.
    //
    // The server cannot produce one today: a parent has to be registered before
    // its children, and the grantable tree omits nothing grantable. It is here
    // because the alternative failure is a permission that exists, is held, and
    // appears NOWHERE in the editor — and an administrator cannot report a row
    // they cannot see.
    final drawn = {for (final tab in tabs) tab.key};

    return [
      for (final module in tabs)
        _RoleSection(
          key: module.key,
          label: module.label,
          modules: [module, ...?children[module.key]],
        ),
      for (final entry in children.entries)
        if (!drawn.contains(entry.key))
          for (final orphan in entry.value)
            _RoleSection(
              key: orphan.key,
              label: orphan.label,
              modules: [orphan],
            ),
      for (final groupKey in const ['organization', 'settings'])
        if (nested[groupKey] case final members?)
          _RoleSection(
            key: groupKey,
            label: members.first.groupLabel.isEmpty
                ? groupKey
                : members.first.groupLabel,
            hint: members.first.groupHint,
            modules: members,
          ),
    ];
  }

  /// One grant, drawn the same whether it opens a tab or permits an act.
  ///
  /// Extracted on 2026-09-17 rather than duplicated into the two groups: the
  /// refusal wording, the requirement line and the narrow-layout threshold all
  /// live here, and a second copy for tabs would be a second place for them to
  /// drift. The rows differ by which list they are drawn FROM, not by how.
  Widget _actionRow(
    BuildContext context,
    PermissionModule module,
    PermissionAction action,
    Color? muted,
    bool live,
    BoxConstraints constraints,
  ) {
    final theme = Theme.of(context);
    final requirement = _requirementLine(
      context,
      action,
      narrow: constraints.maxWidth < 400,
    );
    final description = action.description;

    return CheckboxListTile(
      key: ValueKey('action:${action.key}'),
      dense: true,
      contentPadding: EdgeInsets.zero,
      controlAffinity: ListTileControlAffinity.leading,
      value: _held.contains(action.key),
      onChanged: live && action.grantable
          ? (_) => _toggleAction(module, action)
          : null,
      title: _actionTitle(
        context,
        action,
        muted,
        narrow: constraints.maxWidth < 400,
      ),
      subtitle: description == null || description.isEmpty
          ? requirement
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  description,
                  key: ValueKey('action-description:${action.key}'),
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
                ?requirement,
              ],
            ),
      // Shown rather than hidden, and shown as held when it is held. §4:
      // nobody grants what they do not have — but a role can hold what
      // the person editing it does not, and pretending otherwise draws an
      // editor that misrepresents the role it is describing.
      secondary: action.grantable
          ? null
          // R8: what the person can do about it, in words they recognise
          // from the screen in front of them. The permission key was here
          // and said nothing to anybody who has not read the source.
          : _lockedMark(
              theme,
              'Your own role does not include '
              '${action.label.toLowerCase()} under ${module.label}, so '
              'you cannot hand it out or take it back.',
            ),
    );
  }

  /// A caption over one group of grants, and the sentence that says what the
  /// group is FOR.
  ///
  /// The sentence is not decoration. "Tabs" alone leaves an administrator to
  /// infer whether ticking one opens a pane or permits something inside it —
  /// which is exactly the confusion the split was made to end.
  Widget _grantHeading(ThemeData theme, String heading, String explanation) =>
      Padding(
        padding: const EdgeInsets.only(top: 12, bottom: 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              heading,
              style: theme.textTheme.labelLarge?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            Text(
              explanation,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      );

  /// How many of the organization's inboxes this role can open, said on the
  /// collapsed row.
  ///
  /// Null for a module that has nothing to do with mail, which is all but one of
  /// them — the count only exists where the server sent inboxes.
  ///
  /// This is the whole of the owner's "granting the tab and granting an inbox
  /// are different acts", made visible without opening anything: somebody who
  /// ticks Email and expects that to have included the mail sees "0 of 2
  /// inboxes" sitting under it. A tick that filled in silently would have taught
  /// them the opposite, once, and they would not check again.
  Widget? _inboxTally(PermissionModule module, ThemeData theme) {
    if (!widget.inboxes.nests(module.key)) return null;

    final all = widget.inboxes.under(module.key);
    final words = _wordsFor(widget.inboxes.kindFor(module.key));

    if (all.isEmpty) {
      return Text(words.none, style: theme.textTheme.bodySmall);
    }

    final held = _inboxesUnder(module.key).length;
    // Inboxes given by name open regardless of the role (Jose, 2026-11-10:
    // "No inboxes granted" read as "Lucas cannot read office@nj").
    final own = words.plural == 'inboxes'
        ? widget.role.peopleWithOwnInboxes
        : 0;
    final byName = own == 0
        ? ''
        : ' $own of its ${widget.role.userCount} people '
              '${own == 1 ? 'has' : 'have'} inboxes given to them by name.';

    return Text(
      held == 0
          ? 'No ${words.plural} granted, of ${all.length}.'
                '${own == 0 ? ' The tab on its own opens none.' : byName}'
          : '$held of ${all.length} ${words.plural} granted.$byName',
      style: theme.textTheme.bodySmall,
    );
  }

  /// The inboxes themselves, under the actions they are separate from.
  List<Widget> _inboxRows(
    PermissionModule module,
    ThemeData theme, {
    required bool live,
    required Color? muted,
  }) {
    if (!widget.inboxes.nests(module.key)) return const [];

    final nested = widget.inboxes.under(module.key);
    final words = _wordsFor(widget.inboxes.kindFor(module.key));

    return [
      const Divider(height: 20),
      Padding(
        padding: const EdgeInsets.only(bottom: 4),
        child: Text(
          words.heading,
          style: theme.textTheme.labelLarge?.copyWith(color: muted),
        ),
      ),

      if (nested.isEmpty)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Text(words.empty, style: theme.textTheme.bodySmall),
        )
      else ...[
        Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: Text(words.instruction, style: theme.textTheme.bodySmall),
        ),
        for (final inbox in nested)
          CheckboxListTile(
            key: ValueKey('inbox:${inbox.id}'),
            dense: true,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _inboxes.contains(inbox.id),
            // The same control both ways. Revoking is unticking the box that
            // granted it — there is no second place to go and nothing to undo it
            // from.
            onChanged: live && inbox.grantable
                ? (_) => _toggleInbox(module, inbox)
                : null,
            title: Text(inbox.heading, style: TextStyle(color: muted)),
            // The address as well as the name, whenever they are two different
            // things. "Support" could be any of four addresses, and which mail a
            // role can read is the question this row answers.
            subtitle: inbox.named
                ? Text(inbox.address, style: theme.textTheme.bodySmall)
                : null,
            secondary: inbox.grantable
                ? null
                : _lockedMark(
                    theme,
                    'You cannot open ${inbox.address} yourself, so you cannot '
                    'hand it out or take it back.',
                  ),
          ),
      ],
    ];
  }

  /// The padlock beside a row this person cannot move, with the sentence saying
  /// why. One widget, so a lock never appears without one.
  Widget _lockedMark(ThemeData theme, String reason) => Tooltip(
    message: reason,
    child: Icon(
      Icons.lock_outline,
      size: 16,
      color: theme.colorScheme.onSurfaceVariant,
      // The tooltip is a hover affordance and a pointer is not the only way in
      // (R7). The label is what a screen reader reads, and what a long-press
      // announces on a touch device.
      semanticLabel: reason,
    ),
  );
}
