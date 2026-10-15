import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/brand_colour_card.dart';
import '../widgets/logo_badge.dart';
import '../widgets/refused.dart';
import '../widgets/side_panel.dart';
import '../widgets/structure_canvas.dart';
import '../widgets/structure_menu.dart';
import '../widgets/tab_bar_like.dart';
import 'company_form_panel.dart';
import 'organization/structure_tab.dart';

/// The shape of the business (plan §3).
///
/// Organization → Company → Sub Company → Franchise, and the only screen that
/// can change it. Until this existed, the structure could be edited by somebody
/// with a shell on the server and by nobody else — the model has carried Sub
/// Companies since the foundation and no customer could ever create one.
///
/// Rendered as a tree rather than a list, for the reason §3 exists at all: a
/// Sub Company inherits from its parent before the organization, and a flat
/// list says the opposite.
///
/// ── Two views of one thing ───────────────────────────────────────
///
/// List is where the structure is CHANGED; Structure is where its shape is
/// looked at. They are two pictures of one payload — `GET /companies`, fetched
/// once by this screen and handed to both — so they cannot come to disagree
/// about what exists, and every act on a node is offered by the same menu in
/// both (see `structure_menu.dart`).
///
/// The selected tab is deliberately not remembered. Landing on the canvas after
/// a reload would put somebody one click further from every control that does
/// something, and switching back costs nothing. Anything the Structure tab DOES
/// remember — where the boxes were put — belongs to the ORGANIZATION rather
/// than to the person: one arrangement everybody sees, held as a tenant setting
/// and written by the tab itself. It rides in on the same `GET /companies` this
/// screen already fetches, which is what makes a colleague's tidy-up arrive the
/// next time anybody opens the page.
class OrganizationScreen extends StatefulWidget {
  const OrganizationScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<OrganizationScreen> createState() => _OrganizationScreenState();
}

class _OrganizationScreenState extends State<OrganizationScreen> {
  bool _loading = true;

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it: the kill switch could sit
  /// reading "Off" for a module that was on, until somebody navigated away.
  int _request = 0;
  String? _error;
  Map<String, dynamic>? _tree;

  /// List is 0 and Structure is 1, and List is where this screen opens.
  static const int _list = 0;
  static const int _structure = 1;

  int _tab = _list;

  /// Which tabs have been shown at least once — hr_screen's idiom, and its two
  /// reasons hold identically. A tab nobody has opened must not build: the
  /// canvas lays out every box in the business to do it, and somebody who only
  /// ever looks at the list should pay for none of that.
  /// A tab they HAVE opened must not be rebuilt from scratch every time they
  /// glance at the other one, because the canvas's State is where an
  /// arrangement, a viewport and a pending save are being held.
  final Set<int> _shown = {_list};

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. There is
      // no collection here; the tree IS the screen, and its absence is the
      // honest test. Every edit on this page ends in a refetch, so renaming one
      // place used to tear down the whole structure to show the new name.
      _loading = _tree == null;
      _error = null;
    });

    final response = await widget.api.get('/companies');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _tree = response.ok ? response.body : null;
    });
  }

  Future<void> _send(Future<ApiResponse> Function() call, String done) async {
    final response = await call();

    if (!mounted) return;

    if (!response.ok) {
      // The server's own sentence. Every refusal here explains a rule from §3 —
      // why a company cannot lose its last place, why a sub company cannot sit
      // under a sub company — and a generic "could not save" throws away the
      // only part worth reading.
      _snack(response.reason, isError: true);
      return;
    }

    _snack(done);
    _load();
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

  @override
  Widget build(BuildContext context) {
    final tree = _tree;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: tree == null
          ? const SizedBox.shrink()
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // The heading sits ABOVE the strip, unlike hr_screen and
                // email_screen which have none at all. Those screens have no
                // header ACTION; this one does, and "Add company" belongs to
                // the organization rather than to either view of it — a button
                // that vanished on switching tabs would read as having
                // navigated somewhere else rather than as two pictures of one
                // page. Only the note changes, because only the note is about
                // which picture you are looking at.
                //
                // PanelHeader rather than the hand-rolled Row that used to be
                // here. That Row put a button and a paragraph in one line with
                // no breakpoint, which is the exact shape PanelHeader's
                // docblock exists to replace: at 375 the paragraph was laid out
                // into a column a few characters wide and took the page with
                // it.
                PanelHeader(
                  title: 'Organization',
                  // ── Two sentences, and the second is the rule ──
                  //
                  // Said for a business owner rather than for a developer. It
                  // used to end "nothing here can be moved to a different
                  // parent once it exists", which is the model's vocabulary
                  // and not anybody's: "parent" is a column name. What it says
                  // now is the fact that vocabulary was standing in for — who
                  // owns what is settled when a thing is created and stays
                  // that way — which is the same rule `CompaniesController`
                  // enforces and the same one the canvas explains at length
                  // the moment somebody drops one box onto another.
                  note: _tab == _structure
                      ? 'The same companies, sub companies and places, drawn '
                            'rather than listed. Moving a box moves the picture '
                            'only — who owns what is decided when something is '
                            'created and stays that way afterwards.'
                      // The same rule in the same words, because it is the same
                      // rule. Two tabs of one screen phrasing it differently is
                      // how somebody comes to believe there are two rules, and
                      // this is the one they are most likely to test by trying
                      // it on the other tab.
                      : 'Companies, sub companies and the places work happens. '
                            'Settings cascade down this tree, and who owns what '
                            'is decided when something is created and stays '
                            'that way afterwards.',
                  // Shown either way. It used to vanish for anybody who could
                  // not use it, which reads as a missing feature rather than as
                  // a refusal — and this is the refusal least likely to be
                  // guessed, because it is about POSITION rather than
                  // permission (§4): somebody holding `companies.create` at a
                  // branch has earned the button and still cannot press it.
                  action: Refused(
                    reason: tree['organization']['may_add_company'] == true
                        ? null
                        : tree['organization']['may_add_company_reason']
                                  as String? ??
                              'Adding a company is the whole business’s to do.',
                    child: FilledButton.icon(
                      onPressed: tree['organization']['may_add_company'] == true
                          ? () => _createCompany(parent: null)
                          : null,
                      icon: const Icon(Icons.add, size: 18),
                      label: const Text('Add company'),
                    ),
                  ),
                ),
                Material(
                  color: Theme.of(context).colorScheme.surface,
                  child: TabBarLike(
                    labels: const [
                      ('List', Icons.list_alt_outlined),
                      ('Structure', Icons.account_tree_outlined),
                    ],
                    selected: _tab,
                    onSelected: _select,
                  ),
                ),
                const Divider(height: 1),
                Expanded(
                  // Offstage rather than IndexedStack, for the reason
                  // automations_screen gives: IndexedStack leaves its hidden
                  // children in the semantics tree, so a screen reader would
                  // read out the whole canvas while somebody is looking at the
                  // list. Offstage takes the hidden half out of layout,
                  // painting and semantics and leaves its State exactly where
                  // it was — which here is an arrangement, a viewport and a
                  // pending save on one side, and a fetched tree on the other.
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      Offstage(
                        offstage: _tab != _list,
                        child: _shown.contains(_list)
                            ? _listView(tree)
                            : const SizedBox.shrink(),
                      ),
                      Offstage(
                        offstage: _tab != _structure,
                        child: _shown.contains(_structure)
                            ? StructureTab(
                                api: widget.api,
                                session: widget.session,
                                tree: tree,
                                // Straight off the payload, both of them, and
                                // never worked out here. Saving an arrangement
                                // changes what every colleague sees, so it
                                // needs `organizations.edit` AND an assignment
                                // at the whole organization — and a client
                                // that asked only the permission would offer a
                                // live canvas to a branch manager and answer
                                // 403. The reason beside it is the same string
                                // the endpoint aborts with, which is what stops
                                // a disabled control and the refusal behind it
                                // explaining one rule two ways.
                                mayArrange:
                                    tree['organization']['may_arrange'] == true,
                                arrangeRefusal:
                                    tree['organization']['may_arrange_reason']
                                        as String?,
                                actions: _actionsFor,
                              )
                            : const SizedBox.shrink(),
                      ),
                    ],
                  ),
                ),
              ],
            ),
    );
  }

  Widget _listView(Map<String, dynamic> tree) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 24),
      children: [
        _OrganizationRow(
          api: widget.api,
          organization: tree['organization'] as Map<String, dynamic>,
          onChanged: _load,
        ),

        // Beside the logo, because they are one idea: how this business looks
        // wherever we render it for them. UI/UX brand colour layouts 1 + 6,
        // chosen by Jose 2026-09-09.
        BrandColourCard(
          api: widget.api,
          mayEdit:
              (tree['organization'] as Map<String, dynamic>)['may_edit'] == true,
        ),

        for (final company
            in (tree['companies'] as List<dynamic>).cast<Map<String, dynamic>>())
          _companyBlock(company, isSub: false),
      ],
    );
  }

  /// The same acts the list offers, for a box on the canvas or a row in the
  /// outline.
  ///
  /// Takes the kind and the server's own map rather than either drawing
  /// widget's node class, so this screen never has to know which of the two is
  /// on screen — and so the §4 booleans are read from the payload at the point
  /// of use, which is the whole reason both of those widgets carry `raw`.
  ///
  /// The organization gets nothing. Its one act is "Add company", and that is in
  /// the header above both tabs rather than hidden inside a box.
  Widget? _actionsFor(
    BuildContext context,
    String kind,
    Map<String, dynamic> node,
  ) {
    if (kind == structureCompany) {
      return CompanyActionsMenu(
        company: node,
        onEdit: () => _editCompany(node),
        onToggleActive: () => _toggleCompany(node),
        onAddFranchise: () => _createFranchise(node),
        // §3 fixes the depth: only a top-level company may hold one. Asked of
        // the server's own field rather than of a flag this screen carried
        // down, because the canvas and the outline reach a node by a different
        // route than the list's walk does and `parent_company_id` is the one
        // answer all three can read.
        onAddSubCompany: node['parent_company_id'] == null
            ? () => _createCompany(parent: node)
            : null,
        onMove: () => _moveCompany(node),
      );
    }

    if (kind == structureFranchise) {
      return FranchiseActionsMenu(
        franchise: node,
        onRename: () => _renameFranchise(node),
        onToggle: () => _toggleFranchise(node),
      );
    }

    return null;
  }

  Widget _companyBlock(Map<String, dynamic> company, {required bool isSub}) {
    final subs = (company['sub_companies'] as List<dynamic>? ?? const [])
        .cast<Map<String, dynamic>>();

    return Padding(
      // Sub companies step in once. The indent IS the relationship — it is what
      // says these settings resolve through the company above before reaching
      // the organization.
      padding: EdgeInsets.only(left: isSub ? 36 : 0, top: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _CompanyCard(
            api: widget.api,
            onChanged: _load,
            company: company,
            isSub: isSub,
            onEdit: () => _editCompany(company),
            onToggleActive: () => _toggleCompany(company),
            onAddFranchise: () => _createFranchise(company),
            // §3: depth is fixed. Only a top-level company may hold one.
            onAddSubCompany: isSub
                ? null
                : () => _createCompany(parent: company),
            onMove: () => _moveCompany(company),
            onRenameFranchise: _renameFranchise,
            onToggleFranchise: _toggleFranchise,
          ),
          for (final sub in subs) _companyBlock(sub, isSub: true),
        ],
      ),
    );
  }

  // ── the acts, each written once ──────────────────────────────
  //
  // Named methods rather than lambdas built inside the list's tree walk,
  // because the canvas and the outline reach the same acts by a different route
  // and there must not be a second copy of "what switching a company off
  // means". Every one of them ends in _send, which shows the server's own
  // sentence on a refusal and refetches on success.

  Future<void> _toggleCompany(Map<String, dynamic> company) => _send(
    () => widget.api.patch('/companies/${company['id']}', {
      'is_active': !(company['is_active'] as bool),
    }),
    company['is_active'] as bool ? 'Switched off' : 'Switched back on',
  );

  Future<void> _renameFranchise(Map<String, dynamic> franchise) => _rename(
    title: 'Rename',
    current: franchise['name'] as String,
    onSubmit: (name) => _send(
      () => widget.api.patch('/franchises/${franchise['id']}', {'name': name}),
      'Renamed',
    ),
  );

  Future<void> _toggleFranchise(Map<String, dynamic> franchise) => _send(
    () => widget.api.patch('/franchises/${franchise['id']}', {
      'is_active': !(franchise['is_active'] as bool),
    }),
    franchise['is_active'] as bool ? 'Switched off' : 'Switched back on',
  );

  // ── the two creation flows ───────────────────────────────────

  Future<void> _createCompany({Map<String, dynamic>? parent}) async {
    final tree = _tree;

    if (tree == null) return;

    // Top-level companies only — §3 fixes the depth, so a sub company can never
    // be a parent. Filtering here means the form cannot offer a choice the
    // server would refuse.
    final parents = (tree['companies'] as List<dynamic>)
        .cast<Map<String, dynamic>>()
        .map((c) => (id: c['id'] as String, name: c['name'] as String))
        .toList();

    final draft = await showSidePanel<CompanyDraft>(
      context,
      builder: (_) => CompanyFormPanel(
        api: widget.api,
        parents: parents,
        fixedParent: parent == null
            ? null
            : (id: parent['id'] as String, name: parent['name'] as String),
      ),
    );

    if (draft == null) return;

    await _send(
      () => widget.api.post('/companies', draft.toJson()),
      draft.parentId == null ? 'Company added' : 'Sub company added',
    );
  }

  /// Everything about a company that can change, in the form that created it.
  ///
  /// This used to be "Rename", a single field — so an address typed once at
  /// creation was permanent, and a company that moved had no way to say so
  /// short of the API. The name was the only editable thing about the entity
  /// whose address goes on the invoices.
  Future<void> _editCompany(Map<String, dynamic> company) async {
    final draft = await showSidePanel<CompanyDraft>(
      context,
      builder: (_) =>
          CompanyFormPanel.edit(api: widget.api, company: company),
    );

    if (draft == null) return;

    await _send(
      () =>
          widget.api.patch('/companies/${company['id']}', draft.toUpdateJson()),
      'Saved',
    );
  }

  /// Make a company a sub company, or make a sub company a company again.
  ///
  /// ── Why the list is built here and not asked for ─────────────
  ///
  /// The tree this screen is already holding IS the answer: every valid
  /// destination is a top-level company, and §3 fixes the depth so nothing
  /// deeper can ever be one. Asking the server for a list of candidates would
  /// be a second endpoint answering a question the payload on screen has
  /// already answered, and the two could disagree the moment somebody adds a
  /// company in another window.
  ///
  /// Its own current place is left OUT rather than shown and disabled. A
  /// picker whose first entry is where the thing already is invites the one
  /// choice that does nothing, and the server answers that case with a
  /// cheerful no-op rather than an error, so the screen would say "Moved" and
  /// move nothing.
  Future<void> _moveCompany(Map<String, dynamic> company) async {
    final tree = _tree;

    if (tree == null) return;

    final id = company['id'] as String;
    final parentId = company['parent_company_id'] as String?;

    final destinations = <({String? id, String label})>[
      // Only offered when it is somewhere else now. For a company already at
      // the top this is not a move.
      if (parentId != null)
        (
          id: null,
          label: 'Top level, under ${tree['organization']['name']}',
        ),
      for (final c in (tree['companies'] as List<dynamic>).cast<Map<String, dynamic>>())
        if (c['id'] != id && c['id'] != parentId)
          (id: c['id'] as String, label: 'Under ${c['name']}'),
    ];

    if (destinations.isEmpty) {
      // One company in the business, or one company and its own parent. There
      // is nowhere to put it, and saying so is better than a dialog with an
      // empty list in it.
      _snack('There is nowhere else to put ${company['name']} yet.');

      return;
    }

    // Where it is NOW, as a line under the title rather than a row in the list.
    //
    // Leaving the current place out of the choices is right — picking it moves
    // nothing, and the server answers that with a deliberate no-op, so the
    // screen would say "Moved" and nothing would have happened. But an absence
    // is not a label: without this the picker stopped saying where the thing
    // sits, which is the one fact somebody needs to choose. UI/UX's fix, and
    // better than either shape I had weighed.
    final parent = parentId == null
        ? null
        : (tree['companies'] as List<dynamic>)
              .cast<Map<String, dynamic>>()
              .firstWhere(
                (c) => c['id'] == parentId,
                orElse: () => <String, dynamic>{},
              )['name'] as String?;

    // A side panel, as every other edit here (UI queue 99), not a dialog.
    final chosen = await showSidePanel<({String? id, String label})>(
      context,
      builder: (context) => SidePanel(
        title: 'Move ${company['name']}',
        subtitle: parent == null
            ? 'Currently at the top level.'
            : 'Currently under $parent.',
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final d in destinations)
              ListTile(
                key: ValueKey('org-move-${d.id ?? 'top'}'),
                title: Text(d.label),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).pop(d),
              ),
          ],
        ),
      ),
    );

    if (chosen == null) return;

    await _send(
      () => widget.api.patch('/companies/$id/parent', {
        'parent_company_id': chosen.id,
      }),
      chosen.id == null ? 'Now a company' : 'Now a sub company',
    );
  }

  Future<void> _createFranchise(Map<String, dynamic> company) async {
    final name = await _prompt(
      title: 'Add to ${company['name']}',
      hint: 'Where the work happens — a state, a city, a region.',
      label: 'Name',
    );

    if (name == null) return;

    await _send(
      () => widget.api.post('/franchises', {
        'company_id': company['id'],
        'name': name,
      }),
      'Added',
    );
  }

  Future<void> _rename({
    required String title,
    required String current,
    required Future<void> Function(String) onSubmit,
  }) async {
    final name = await _prompt(title: title, label: 'Name', initial: current);

    if (name == null || name == current) return;

    await onSubmit(name);
  }

  Future<String?> _prompt({
    required String title,
    required String label,
    String? hint,
    String initial = '',
  }) async {
    final controller = TextEditingController(text: initial);

    final value = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        title: title,
        // The field's helper moves up to the subtitle: one explanation, said
        // once, above the thing it explains.
        subtitle: hint,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(controller.text.trim()),
            child: const Text('Save'),
          ),
        ],
        child: TextField(
          controller: controller,
          autofocus: true,
          decoration: InputDecoration(
            labelText: label,
            border: const OutlineInputBorder(),
          ),
          onSubmitted: (v) => Navigator.of(context).pop(v.trim()),
        ),
      ),
    );

    controller.dispose();

    return (value == null || value.isEmpty) ? null : value;
  }
}

// ── rows ───────────────────────────────────────────────────────

class _OrganizationRow extends StatelessWidget {
  const _OrganizationRow({
    required this.api,
    required this.organization,
    required this.onChanged,
  });

  final ApiClient api;
  final Map<String, dynamic> organization;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        LogoBadge(
          api: api,
          path: '/branding/organization',
          version: organization['logo_version'] as String? ?? '',
          hasOwn: organization['has_logo'] == true,
          aspect: organization['logo_aspect'] as String?,
          mayEdit: organization['may_edit'] == true,
          onChanged: onChanged,
          size: 52,
          fallbackIcon: Icons.hub_outlined,
        ),
        const SizedBox(width: 14),
        // Bounded, for the reason the company card's own row is (R7). A Column
        // of Texts in a Row keeps the intrinsic width of its widest line, so
        // this sentence laid itself out at full length beside a 52pt badge and
        // ran off the right of a 375-point window — measured on this fixture,
        // and silently clipped rather than reported in a release build.
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                organization['name'] as String,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(
                  context,
                ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
              ),
              // Deliberately uncapped. It is one sentence and it is the only
              // thing on the screen that says what the organization row IS, so
              // truncating it would remove the explanation rather than shorten
              // it — it wraps to as many lines as the width needs.
              Text(
                'the whole business — every company inherits this mark',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _CompanyCard extends StatelessWidget {
  const _CompanyCard({
    required this.api,
    required this.onChanged,
    required this.company,
    required this.isSub,
    required this.onEdit,
    required this.onToggleActive,
    required this.onAddFranchise,
    required this.onAddSubCompany,
    required this.onMove,
    required this.onRenameFranchise,
    required this.onToggleFranchise,
  });

  final ApiClient api;
  final VoidCallback onChanged;
  final Map<String, dynamic> company;
  final bool isSub;
  final VoidCallback onEdit;
  final VoidCallback onToggleActive;
  final VoidCallback onAddFranchise;
  final VoidCallback? onAddSubCompany;
  final VoidCallback onMove;
  final void Function(Map<String, dynamic>) onRenameFranchise;
  final void Function(Map<String, dynamic>) onToggleFranchise;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final active = company['is_active'] as bool;
    final mayEdit = company['may_edit'] as bool? ?? false;

    // The answer for the "Add a place" chip below, which is the same answer the
    // menu asks the same map for — §4 has one home and it is the server. This
    // one used to be drawn from `may_edit` — `companies.edit`, which is not the
    // permission it needs: `franchises.create`, asked against this company. So
    // it was taken away from people who held it and offered to people who did
    // not, and either way it said nothing.
    final mayAddFranchise = company['may_add_franchise'] as bool? ?? false;
    final noAddFranchise = mayAddFranchise
        ? null
        : company['may_add_franchise_reason'] as String? ??
              'Adding a place to ${company['name']} is not yours to do.';

    final franchises = (company['franchises'] as List<dynamic>)
        .cast<Map<String, dynamic>>();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                LogoBadge(
                  api: api,
                  path: '/branding/companies/${company['id']}',
                  version: company['logo_version'] as String? ?? '',
                  hasOwn: company['has_logo'] == true,
                  aspect: company['logo_aspect'] as String?,
                  mayEdit: mayEdit,
                  onChanged: onChanged,
                  size: 36,
                ),
                const SizedBox(width: 12),
                // ── Bounded and wrapping, because R7 is not a wide desktop ──
                //
                // These were four unbounded children and a Spacer, which is a
                // Row that only fits by luck: a Text takes its intrinsic width
                // in a Row, so a company called anything longer than a word
                // overflowed the card the moment the window narrowed. Measured
                // on this fixture at 375: over by 129 points on a top-level
                // company and by more on a sub company, which pays the indent
                // as well. Nothing on screen said so — an overflow is a stripe
                // in debug and silently clipped content in release.
                //
                // Expanded gives the run the space the menu does not need, and
                // takes the Spacer's job with it. A Wrap inside it so the two
                // badges drop to a second line rather than squeezing the name
                // they are about, and the name ellipsizes rather than growing
                // the card without limit — the whole name is on the row's own
                // semantics either way.
                Expanded(
                  child: Wrap(
                    crossAxisAlignment: WrapCrossAlignment.center,
                    spacing: 8,
                    runSpacing: 2,
                    children: [
                      Text(
                        company['name'] as String,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 15,
                          color: active ? null : scheme.onSurfaceVariant,
                        ),
                      ),
                      if (isSub)
                        Text(
                          'sub company',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      if (!active)
                        Text(
                          'switched off',
                          style: Theme.of(
                            context,
                          ).textTheme.bodySmall?.copyWith(color: scheme.error),
                        ),
                    ],
                  ),
                ),
                // The same menu the canvas box and the outline row draw, so the
                // three views cannot come to offer different acts under
                // different answers. It decides for itself whether there is
                // anything in it worth drawing.
                CompanyActionsMenu(
                  company: company,
                  onEdit: onEdit,
                  onToggleActive: onToggleActive,
                  onAddFranchise: onAddFranchise,
                  onAddSubCompany: onAddSubCompany,
                  onMove: onMove,
                ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final f in franchises)
                  _FranchiseChip(
                    franchise: f,
                    onRename: () => onRenameFranchise(f),
                    onToggle: () => onToggleFranchise(f),
                  ),
                Refused(
                  reason: noAddFranchise,
                  child: ActionChip(
                    avatar: const Icon(Icons.add, size: 15),
                    label: const Text('Add a place'),
                    onPressed: mayAddFranchise ? onAddFranchise : null,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _FranchiseChip extends StatelessWidget {
  const _FranchiseChip({
    required this.franchise,
    required this.onRename,
    required this.onToggle,
  });

  final Map<String, dynamic> franchise;
  final VoidCallback onRename;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final active = franchise['is_active'] as bool;
    final scheme = Theme.of(context).colorScheme;
    final name = franchise['name'] as String;

    // The chip itself is the menu's anchor, so the place and the way to act on
    // it are one target. The canvas box and the outline row hand it no child
    // and get the plain handle instead — same menu, same answers, different
    // thing to hang off.
    return FranchiseActionsMenu(
      franchise: franchise,
      onRename: onRename,
      onToggle: onToggle,
      child: Semantics(
        // Being switched off was carried by grey text and a line through it.
        // The line is not colour, so it survives a reader who cannot see the
        // grey — but neither reaches a screen reader, which got the name and
        // nothing else.
        label: active ? name : '$name — switched off',
        child: Chip(
          avatar: Icon(
            Icons.storefront_outlined,
            size: 15,
            color: active ? null : scheme.onSurfaceVariant,
          ),
          label: Text(
            name,
            style: TextStyle(
              color: active ? null : scheme.onSurfaceVariant,
              decoration: active ? null : TextDecoration.lineThrough,
            ),
          ),
        ),
      ),
    );
  }
}
