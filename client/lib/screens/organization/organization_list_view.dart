import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/address_fields.dart';
import '../../widgets/brand_colour_card.dart';
import '../../widgets/logo_badge.dart';
import '../../widgets/not_filled_in.dart';
import '../../widgets/refused.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/structure_levels.dart';
import '../../widgets/structure_menu.dart';
import '../../widgets/tab_bar_like.dart';
import '../settings_screen.dart' show SettingsScreen;

/// Below this width of the tab itself, the tree is drawn alone and a node's
/// page replaces it (UI queue 111, phone 4).
const double organizationListTwoPanesAbove = 760;

/// The Organization tab's List view: the tree on the left, the picked node on
/// the right (UI queue 111, layout 1, chosen by Jose 2026-10-19).
///
/// It draws and it selects; it never writes. Every act is a callback into the
/// screen, which owns the one copy of each — the same ones the canvas and the
/// outline reach through [CompanyActionsMenu] and [FranchiseActionsMenu] — and
/// every §4 answer is read off the server's own map at the point of use.
///
/// ── Numbers it is not given are not drawn ────────────────────────
///
/// People, phone numbers and connections come from an optional `counts` map
/// on each node of `GET /companies`. A server that does not send it gets the
/// one number this side can count for itself — how many places sit under the
/// node — and nothing else. A zero standing in for "not told" would be a claim.
class OrganizationListView extends StatefulWidget {
  const OrganizationListView({
    super.key,
    required this.api,
    required this.session,
    required this.tree,
    required this.query,
    required this.onChanged,
    required this.onEditCompany,
    required this.onToggleCompany,
    required this.onAddFranchise,
    required this.onAddSubCompany,
    required this.onMoveCompany,
    required this.onRenameFranchise,
    required this.onToggleFranchise,
    this.onPageOpen,
  });

  /// Told when a node's page replaces the tree on a narrow tab, and when Back
  /// returns to it, so the screen can give the page its header's height.
  final ValueChanged<bool>? onPageOpen;

  final ApiClient api;
  final Session session;

  /// The `GET /companies` payload, untouched.
  final Map<String, dynamic> tree;

  /// What is typed in the header's search field.
  final String query;

  /// A logo changed, so the tree is refetched.
  final VoidCallback onChanged;

  final void Function(Map<String, dynamic> company) onEditCompany;
  final void Function(Map<String, dynamic> company) onToggleCompany;
  final void Function(Map<String, dynamic> company) onAddFranchise;
  final void Function(Map<String, dynamic> company) onAddSubCompany;
  final void Function(Map<String, dynamic> company) onMoveCompany;
  final void Function(Map<String, dynamic> franchise) onRenameFranchise;
  final void Function(Map<String, dynamic> franchise) onToggleFranchise;

  @override
  State<OrganizationListView> createState() => _OrganizationListViewState();
}

/// One row of the tree.
class _Node {
  const _Node({
    required this.kind,
    required this.raw,
    required this.depth,
    this.parent,
  });

  /// [structureOrganization], [structureCompany] or [structureFranchise].
  final String kind;
  final Map<String, dynamic> raw;
  final int depth;

  /// The company a franchise or a sub company belongs to.
  final Map<String, dynamic>? parent;

  String get key => '$kind:${raw['id']}';
  String get name => raw['name'] as String? ?? '';
}

class _OrganizationListViewState extends State<OrganizationListView> {
  /// `kind:id` of the picked node. Kept by id, so a refetch after an edit keeps
  /// the same node open; one that has gone falls back to the organization.
  String? _selected;

  /// On a narrow tab: whether the picked node's page is replacing the tree.
  bool _open = false;

  Map<String, dynamic> get _organization =>
      widget.tree['organization'] as Map<String, dynamic>;

  List<Map<String, dynamic>> get _companies =>
      (widget.tree['companies'] as List<dynamic>? ?? const [])
          .cast<Map<String, dynamic>>();

  String get _placeWord =>
      widget.session.me?.organization.franchiseLabel ?? 'Franchise';

  /// Every node, in tree order, whatever the search says.
  List<_Node> _all() {
    final nodes = <_Node>[
      _Node(kind: structureOrganization, raw: _organization, depth: 0),
    ];

    void company(
      Map<String, dynamic> c,
      int depth,
      Map<String, dynamic>? parent,
    ) {
      nodes.add(
        _Node(kind: structureCompany, raw: c, depth: depth, parent: parent),
      );
      for (final f in _franchisesOf(c)) {
        nodes.add(
          _Node(kind: structureFranchise, raw: f, depth: depth + 1, parent: c),
        );
      }
      for (final sub in _subsOf(c)) {
        company(sub, depth + 1, c);
      }
    }

    for (final c in _companies) {
      company(c, 1, null);
    }

    return nodes;
  }

  /// The rows the search leaves. A company that matches keeps everything under
  /// it; one that does not is kept only to hold a match beneath it, so a branch
  /// found by name is still shown where it sits.
  List<_Node> _visible() {
    final q = widget.query.trim().toLowerCase();
    final organization = _Node(
      kind: structureOrganization,
      raw: _organization,
      depth: 0,
    );

    if (q.isEmpty) return _all();

    bool matches(Map<String, dynamic> m) =>
        (m['name'] as String? ?? '').toLowerCase().contains(q);

    final rows = <_Node>[organization];

    void company(
      Map<String, dynamic> c,
      int depth,
      Map<String, dynamic>? parent, {
      required bool keepAll,
    }) {
      final mine = keepAll || matches(c);
      final places = [
        for (final f in _franchisesOf(c))
          if (mine || matches(f)) f,
      ];
      final start = rows.length;

      for (final sub in _subsOf(c)) {
        company(sub, depth + 1, c, keepAll: mine);
      }

      final subRows = rows.sublist(start);
      rows.removeRange(start, rows.length);

      if (!mine && places.isEmpty && subRows.isEmpty) return;

      rows.add(
        _Node(kind: structureCompany, raw: c, depth: depth, parent: parent),
      );
      for (final f in places) {
        rows.add(
          _Node(kind: structureFranchise, raw: f, depth: depth + 1, parent: c),
        );
      }
      rows.addAll(subRows);
    }

    for (final c in _companies) {
      company(c, 1, null, keepAll: false);
    }

    return rows;
  }

  _Node _current(List<_Node> all) =>
      all.firstWhere((n) => n.key == _selected, orElse: () => all.first);

  void _pick(_Node node) {
    setState(() {
      _selected = node.key;
      _open = true;
    });
    widget.onPageOpen?.call(true);
  }

  @override
  Widget build(BuildContext context) {
    final all = _all();
    final rows = _visible();
    final current = _current(all);

    return LayoutBuilder(
      builder: (context, constraints) {
        final narrow = constraints.maxWidth < organizationListTwoPanesAbove;

        if (narrow) {
          return _open
              ? _detail(
                  current,
                  back: () {
                    setState(() => _open = false);
                    widget.onPageOpen?.call(false);
                  },
                )
              : _treePane(rows, current, narrow: true);
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              width: 260,
              child: _treePane(rows, current, narrow: false),
            ),
            const VerticalDivider(width: 1),
            Expanded(child: _detail(current)),
          ],
        );
      },
    );
  }

  // ── the tree ───────────────────────────────────────────────────

  Widget _treePane(List<_Node> rows, _Node current, {required bool narrow}) {
    final q = widget.query.trim();

    return ListView(
      key: const ValueKey('org-tree'),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
      children: [
        for (final node in rows)
          _TreeRow(
            key: ValueKey('org-tree-${node.kind}-${node.raw['id']}'),
            node: node,
            selected: !narrow && node.key == current.key,
            people: _people(node.raw),
            showChevron: narrow,
            onTap: () => _pick(node),
          ),
        if (q.isNotEmpty && rows.length == 1)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              'No company or ${_placeWord.toLowerCase()} is called “$q”.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
      ],
    );
  }

  // ── the picked node ────────────────────────────────────────────

  Widget _detail(_Node node, {VoidCallback? back}) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final active = node.raw['is_active'] as bool? ?? true;
    final kindLabel = switch (node.kind) {
      structureOrganization => 'Organization',
      structureCompany =>
        node.raw['parent_company_id'] == null ? 'Company' : 'Sub company',
      _ => _placeWord,
    };

    final logo = switch (node.kind) {
      structureOrganization => LogoBadge(
        api: widget.api,
        path: '/branding/organization',
        version: node.raw['logo_version'] as String? ?? '',
        hasOwn: node.raw['has_logo'] == true,
        aspect: node.raw['logo_aspect'] as String?,
        mayEdit: node.raw['may_edit'] == true,
        onChanged: widget.onChanged,
        size: 44,
        fallbackIcon: Icons.hub_outlined,
      ),
      structureCompany => LogoBadge(
        api: widget.api,
        path: '/branding/companies/${node.raw['id']}',
        version: node.raw['logo_version'] as String? ?? '',
        hasOwn: node.raw['has_logo'] == true,
        aspect: node.raw['logo_aspect'] as String?,
        mayEdit: node.raw['may_edit'] as bool? ?? false,
        onChanged: widget.onChanged,
        size: 44,
      ),
      _ => null,
    };

    final stats = _stats(node);

    return _NodePage(
      key: ValueKey('org-detail-${node.key}'),
      header: [
        Wrap(
          spacing: 10,
          runSpacing: 10,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (back != null)
              IconButton(
                key: const ValueKey('org-detail-back'),
                tooltip: 'Back to the tree',
                icon: const Icon(Icons.arrow_back),
                onPressed: back,
              ),
            ?logo,
            Text(
              node.name,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: active ? null : scheme.onSurfaceVariant,
              ),
            ),
            StatusPill(kindLabel),
            if (!active)
              Text(
                'switched off',
                style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
              ),
            ..._actions(node),
          ],
        ),
        if (stats.isNotEmpty) ...[
          const SizedBox(height: 16),
          Wrap(
            key: const ValueKey('org-detail-numbers'),
            spacing: 10,
            runSpacing: 10,
            children: stats,
          ),
        ],
      ],
      details: ListView(
        key: const ValueKey('org-details'),
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        children: _details(node),
      ),
      settings: SettingsScreen(
        key: ValueKey('org-settings-${node.key}'),
        api: widget.api,
        session: widget.session,
        scopeType: node.kind,
        scopeId: node.raw['id'] as String,
        scopeLabel: node.name,
        startSetHere: true,
      ),
    );
  }

  /// The node's acts as buttons, each refused with the server's own sentence
  /// when it may not be pressed — the same answers [CompanyActionsMenu] reads,
  /// from [companyRefusals], so the row and the menu cannot disagree.
  List<Widget> _actions(_Node node) {
    final raw = node.raw;

    if (node.kind == structureCompany) {
      final no = companyRefusals(raw);
      final active = raw['is_active'] as bool? ?? true;
      final topLevel = raw['parent_company_id'] == null;

      return [
        Refused(
          reason: no.edit,
          child: OutlinedButton.icon(
            onPressed: no.edit == null ? () => widget.onEditCompany(raw) : null,
            icon: const Icon(Icons.edit_outlined, size: 18),
            label: const Text('Edit'),
          ),
        ),
        Refused(
          reason: no.addFranchise,
          child: OutlinedButton.icon(
            onPressed: no.addFranchise == null
                ? () => widget.onAddFranchise(raw)
                : null,
            icon: const Icon(Icons.add, size: 18),
            label: Text('Add a ${_placeWord.toLowerCase()}'),
          ),
        ),
        // §3 fixes the depth: a sub company can never hold one, so the button
        // is absent there rather than refused.
        if (topLevel)
          Refused(
            reason: no.addSub,
            child: OutlinedButton.icon(
              onPressed: no.addSub == null
                  ? () => widget.onAddSubCompany(raw)
                  : null,
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add a sub company'),
            ),
          ),
        Refused(
          reason: no.toggle,
          child: OutlinedButton.icon(
            // Switching off reads red, not like Rename beside it (phone
            // audit 2026-11-04); switching back on stays plain.
            style: active ? _danger(context) : null,
            onPressed: no.toggle == null
                ? () => widget.onToggleCompany(raw)
                : null,
            icon: Icon(
              active ? Icons.archive_outlined : Icons.unarchive_outlined,
              size: 18,
            ),
            label: Text(active ? 'Switch off' : 'Switch back on'),
          ),
        ),
        // Everything, including what it sits under, in the menu the canvas and
        // the outline draw.
        CompanyActionsMenu(
          company: raw,
          onEdit: () => widget.onEditCompany(raw),
          onToggleActive: () => widget.onToggleCompany(raw),
          onAddFranchise: () => widget.onAddFranchise(raw),
          onAddSubCompany: topLevel ? () => widget.onAddSubCompany(raw) : null,
          onMove: () => widget.onMoveCompany(raw),
        ),
      ];
    }

    if (node.kind == structureFranchise) {
      final mayEdit = raw['may_edit'] as bool? ?? false;
      final active = raw['is_active'] as bool? ?? true;
      // One answer for both acts, as [FranchiseActionsMenu] gives it.
      final no = mayEdit
          ? null
          : raw['may_edit_reason'] as String? ??
                'This one is not yours to change.';

      return [
        Refused(
          reason: no,
          child: OutlinedButton.icon(
            onPressed: mayEdit ? () => widget.onRenameFranchise(raw) : null,
            icon: const Icon(Icons.edit_outlined, size: 18),
            label: const Text('Rename'),
          ),
        ),
        Refused(
          reason: no,
          child: OutlinedButton.icon(
            style: active ? _danger(context) : null,
            onPressed: mayEdit ? () => widget.onToggleFranchise(raw) : null,
            icon: Icon(
              active ? Icons.archive_outlined : Icons.unarchive_outlined,
              size: 18,
            ),
            label: Text(active ? 'Switch off' : 'Switch back on'),
          ),
        ),
      ];
    }

    // The organization's one act, Add company, is in the header above.
    return const [];
  }

  // ── numbers ────────────────────────────────────────────────────

  List<Widget> _stats(_Node node) {
    final counts = node.raw['counts'];
    final map = counts is Map ? counts : const {};

    int? told(String key) => map[key] is int ? map[key] as int : null;

    final places = switch (node.kind) {
      structureOrganization => _companies.fold<int>(
        0,
        (sum, c) => sum + _placesUnder(c),
      ),
      structureCompany => _placesUnder(node.raw),
      _ => null,
    };

    final people = told('people');
    final numbers = told('numbers');
    final connections = told('connections');

    return [
      if (places != null)
        _Stat(
          key: const ValueKey('org-stat-places'),
          value: places,
          label: _plural(_placeWord.toLowerCase(), places),
        ),
      if (people != null)
        _Stat(
          key: const ValueKey('org-stat-people'),
          value: people,
          label: people == 1 ? 'person' : 'people',
        ),
      if (numbers != null)
        _Stat(
          key: const ValueKey('org-stat-numbers'),
          value: numbers,
          label: numbers == 1 ? 'phone number' : 'phone numbers',
        ),
      if (connections != null)
        _Stat(
          key: const ValueKey('org-stat-connections'),
          value: connections,
          label: connections == 1 ? 'connection' : 'connections',
        ),
    ];
  }

  int _placesUnder(Map<String, dynamic> company) =>
      _franchisesOf(company).length +
      _subsOf(company).fold<int>(0, (sum, s) => sum + _placesUnder(s));

  // ── details ────────────────────────────────────────────────────

  List<Widget> _details(_Node node) {
    final theme = Theme.of(context);
    final raw = node.raw;

    if (node.kind == structureOrganization) {
      return [
        Text(
          'The whole business — every company inherits this mark.',
          style: theme.textTheme.bodyMedium,
        ),
        // Beside the logo, because they are one idea: how this business looks
        // wherever we render it for them. UI/UX brand colour layouts 1 + 6,
        // chosen by Jose 2026-09-09.
        BrandColourCard(api: widget.api, mayEdit: raw['may_edit'] == true),
      ];
    }

    if (node.kind == structureCompany && raw['may_edit'] == true) {
      return [
        if (node.parent != null) ...[
          Text(
            'Sub company of ${node.parent!['name']}',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 12),
        ],
        _CompanyForm(
          key: ValueKey('org-form-${raw['id']}'),
          api: widget.api,
          company: raw,
          onSaved: widget.onChanged,
        ),
      ];
    }

    if (node.kind == structureCompany) {
      String part(String key) => (raw[key] as String? ?? '').trim();

      final cityLine = [
        part('city'),
        [
          part('state'),
          part('postal_code'),
        ].where((s) => s.isNotEmpty).join(' '),
      ].where((s) => s.isNotEmpty).join(', ');
      final address = [
        part('address_line1'),
        part('address_line2'),
        cityLine,
      ].where((s) => s.isNotEmpty).join(', ');
      final phone = part('phone');
      final parent = node.parent;

      return [
        if (parent != null)
          Text(
            'Sub company of ${parent['name']}',
            style: theme.textTheme.bodyMedium,
          ),
        if (address.isNotEmpty)
          Text(address, style: theme.textTheme.bodyMedium),
        if (phone.isNotEmpty) Text(phone, style: theme.textTheme.bodyMedium),
        if (address.isEmpty && phone.isEmpty)
          Text('No address or phone yet.', style: theme.textTheme.bodySmall),
      ];
    }

    final parent = node.parent;
    final facts = [
      raw['timezone'] as String? ?? '',
      raw['currency'] as String? ?? '',
    ].where((s) => s.isNotEmpty).join(' · ');

    return [
      if (parent != null)
        Text('Part of ${parent['name']}', style: theme.textTheme.bodyMedium),
      if (facts.isNotEmpty) Text(facts, style: theme.textTheme.bodyMedium),
      if (raw['may_edit'] == true) ...[
        const SizedBox(height: 12),
        _FranchiseForm(
          key: ValueKey('org-form-${raw['id']}'),
          api: widget.api,
          franchise: raw,
          onSaved: widget.onChanged,
        ),
      ],
    ];
  }

  // ── people ─────────────────────────────────────────────────────

  int? _people(Map<String, dynamic> raw) {
    final counts = raw['counts'];

    if (counts is! Map) return null;

    final people = counts['people'];

    return people is int ? people : null;
  }
}

List<Map<String, dynamic>> _franchisesOf(Map<String, dynamic> company) =>
    (company['franchises'] as List<dynamic>? ?? const [])
        .cast<Map<String, dynamic>>();

List<Map<String, dynamic>> _subsOf(Map<String, dynamic> company) =>
    (company['sub_companies'] as List<dynamic>? ?? const [])
        .cast<Map<String, dynamic>>();

/// "franchise" → "franchises", "territory" → "territories", "branch" →
/// "branches". The organization names its own places, so the plural is made
/// here rather than looked up.
String _plural(String word, int count) {
  if (count == 1) return word;
  if (RegExp(r'[^aeiou]y$').hasMatch(word)) {
    return '${word.substring(0, word.length - 1)}ies';
  }
  if (RegExp(r'(s|x|z|ch|sh)$').hasMatch(word)) return '${word}es';

  return '${word}s';
}

class _TreeRow extends StatelessWidget {
  const _TreeRow({
    super.key,
    required this.node,
    required this.selected,
    required this.people,
    required this.showChevron,
    required this.onTap,
  });

  final _Node node;
  final bool selected;
  final int? people;
  final bool showChevron;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final active = node.raw['is_active'] as bool? ?? true;
    final icon = switch (node.kind) {
      structureOrganization => Icons.hub_outlined,
      structureCompany => Icons.apartment_outlined,
      _ => Icons.storefront_outlined,
    };

    return Semantics(
      selected: selected,
      button: true,
      label: active ? null : '${node.name} — switched off',
      child: Material(
        color: selected ? scheme.secondaryContainer : scheme.surface,
        borderRadius: BorderRadius.circular(8),
        child: InkWell(
          borderRadius: BorderRadius.circular(8),
          onTap: onTap,
          child: Padding(
            padding: EdgeInsets.fromLTRB(8.0 + 16.0 * node.depth, 9, 8, 9),
            child: Row(
              children: [
                Icon(
                  icon,
                  size: 17,
                  color: active ? null : scheme.onSurfaceVariant,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    node.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontWeight: node.kind == structureFranchise
                          ? FontWeight.w400
                          : FontWeight.w600,
                      color: active ? null : scheme.onSurfaceVariant,
                      decoration: active ? null : TextDecoration.lineThrough,
                    ),
                  ),
                ),
                if (people != null)
                  Text('$people', style: theme.textTheme.bodySmall),
                if (showChevron) ...[
                  const SizedBox(width: 4),
                  const Icon(Icons.chevron_right, size: 18),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({super.key, required this.value, required this.label});

  final int value;
  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      width: 132,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '$value',
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          Text(label, style: theme.textTheme.bodySmall),
        ],
      ),
    );
  }
}

/// The settings chosen at exactly this node, by the rule the Preferences tab
/// counts "Set here" with ([sourceIsOwn]).
/// A place's page: its header and numbers, then Details and Settings as two
/// tabs sharing the rest of the height (Jose, 2026-10-20: "most of the
/// editable stuff just in the main screen").
class _NodePage extends StatefulWidget {
  const _NodePage({
    super.key,
    required this.header,
    required this.details,
    required this.settings,
  });

  final List<Widget> header;
  final Widget details;
  final Widget settings;

  @override
  State<_NodePage> createState() => _NodePageState();
}

class _NodePageState extends State<_NodePage> {
  int _tab = 0;
  bool _settingsShown = false;

  @override
  Widget build(BuildContext context) {
    // A short window scrolls the whole page, giving the tabs a height of
    // their own, rather than squeezing them to nothing.
    return KeyedSubtree(
      key: const ValueKey('org-detail'),
      child: LayoutBuilder(
        builder: (context, box) => box.maxHeight < 560
            ? SingleChildScrollView(
                child: SizedBox(height: 560, child: _page(context)),
              )
            : _page(context),
      ),
    );
  }

  Widget _page(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: widget.header,
          ),
        ),
        TabBarLike(
          key: const ValueKey('org-page-tabs'),
          level: TabLevel.secondary,
          labels: const [
            ('Details', Icons.edit_note_outlined),
            ('Settings', Icons.tune_outlined),
          ],
          selected: _tab,
          onSelected: (i) => setState(() {
            _tab = i;
            if (i == 1) _settingsShown = true;
          }),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              Offstage(offstage: _tab != 0, child: widget.details),
              // Built the first time it is opened, then kept.
              Offstage(
                offstage: _tab != 1,
                child: _settingsShown
                    ? widget.settings
                    : const SizedBox.shrink(),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// A company's name, address and phone, edited where they are read; an empty
/// box is amber, as everywhere else that marks what is not filled in.
class _CompanyForm extends StatefulWidget {
  const _CompanyForm({
    super.key,
    required this.api,
    required this.company,
    required this.onSaved,
  });

  final ApiClient api;
  final Map<String, dynamic> company;
  final VoidCallback onSaved;

  @override
  State<_CompanyForm> createState() => _CompanyFormState();
}

class _CompanyFormState extends State<_CompanyForm> {
  late final _name = _field('name');
  late final _line1 = _field('address_line1');
  late final _line2 = _field('address_line2');
  late final _city = _field('city');
  late final _state = _field('state');
  late final _postal = _field('postal_code');
  late final _phone = _field('phone');
  late String _baseline = _now;
  bool _saving = false;
  String? _error;

  TextEditingController _field(String key) =>
      TextEditingController(text: widget.company[key] as String? ?? '')
        ..addListener(() => setState(() {}));

  List<TextEditingController> get _all => [
    _name,
    _line1,
    _line2,
    _city,
    _state,
    _postal,
    _phone,
  ];

  String get _now => _all.map((c) => c.text.trim()).join('|');

  @override
  void dispose() {
    for (final c in _all) {
      c.dispose();
    }
    super.dispose();
  }

  String? _orNull(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final response = await widget.api
        .patch('/companies/${widget.company['id']}', {
          'name': _name.text.trim(),
          'address_line1': _orNull(_line1),
          'address_line2': _orNull(_line2),
          'city': _orNull(_city),
          'state': _orNull(_state)?.toUpperCase(),
          'postal_code': _orNull(_postal),
          'phone': _orNull(_phone),
        });
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (response.ok) {
        _baseline = _now;
      } else {
        _error = response.message;
      }
    });
    if (response.ok) widget.onSaved();
  }

  @override
  Widget build(BuildContext context) {
    final dirty = _now != _baseline;

    return Column(
      key: const ValueKey('org-company-form'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('org-company-name'),
          controller: _name,
          decoration: notFilledIn(
            context,
            const InputDecoration(labelText: 'Name'),
            empty: _name.text.trim().isEmpty,
          ),
        ),
        const SizedBox(height: 16),
        AddressFields(
          api: widget.api,
          line1: _line1,
          line2: _line2,
          city: _city,
          state: _state,
          postal: _postal,
          flagEmpty: true,
        ),
        const SizedBox(height: 16),
        TextField(
          key: const ValueKey('org-company-phone'),
          controller: _phone,
          keyboardType: TextInputType.phone,
          decoration: notFilledIn(
            context,
            const InputDecoration(labelText: 'Phone'),
            empty: _phone.text.trim().isEmpty,
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          Text(
            _error!,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ],
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton(
            key: const ValueKey('org-company-save'),
            onPressed: dirty && !_saving && _name.text.trim().isNotEmpty
                ? _save
                : null,
            child: Text(_saving ? 'Saving…' : 'Save changes'),
          ),
        ),
      ],
    );
  }
}

/// A branch's name, edited in place.
class _FranchiseForm extends StatefulWidget {
  const _FranchiseForm({
    super.key,
    required this.api,
    required this.franchise,
    required this.onSaved,
  });

  final ApiClient api;
  final Map<String, dynamic> franchise;
  final VoidCallback onSaved;

  @override
  State<_FranchiseForm> createState() => _FranchiseFormState();
}

class _FranchiseFormState extends State<_FranchiseForm> {
  late final _name = TextEditingController(
    text: widget.franchise['name'] as String? ?? '',
  )..addListener(() => setState(() {}));
  late String _baseline = _name.text.trim();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    final response = await widget.api.patch(
      '/franchises/${widget.franchise['id']}',
      {'name': _name.text.trim()},
    );
    if (!mounted) return;
    setState(() {
      _saving = false;
      _error = response.ok ? null : response.message;
      if (response.ok) _baseline = _name.text.trim();
    });
    if (response.ok) widget.onSaved();
  }

  @override
  Widget build(BuildContext context) {
    final name = _name.text.trim();
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: TextField(
            key: const ValueKey('org-franchise-name'),
            controller: _name,
            decoration: notFilledIn(
              context,
              InputDecoration(labelText: 'Name', errorText: _error),
              empty: name.isEmpty,
            ),
          ),
        ),
        const SizedBox(width: 12),
        FilledButton(
          key: const ValueKey('org-franchise-save'),
          onPressed: name != _baseline && name.isNotEmpty && !_saving
              ? _save
              : null,
          child: Text(_saving ? 'Saving…' : 'Save'),
        ),
      ],
    );
  }
}

ButtonStyle _danger(BuildContext context) {
  final error = Theme.of(context).colorScheme.error;
  return OutlinedButton.styleFrom(
    foregroundColor: error,
    side: BorderSide(color: error),
  );
}
