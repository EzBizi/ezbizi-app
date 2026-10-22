import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import 'logo_badge.dart';
import 'side_panel.dart';

/// Choosing which part of the business to look at, at any size (plan §3).
///
/// ── Why this replaced a dropdown ─────────────────────────────────
///
/// The switcher was a PopupMenu listing every company, sub company and
/// franchise in one flat run. At four companies and thirteen places it was
/// already taller than the window; at twenty it would be unusable — and not
/// because it scrolls. Scrolling is not the failure. Reading fifty near-identical
/// rows to find one is.
///
/// So the list stays a tree, and search does the scaling. Typing narrows it to
/// what matches, which is the only interaction that works the same at five
/// entries and five hundred.
///
/// Search also happens to solve the other problem: two companies each operating
/// a Connecticut. Typing "conn" shows both, each under the company that owns
/// it, which is exactly the question somebody has at that moment.
///
/// ── A panel, not a dialog ────────────────────────────────────────
///
/// It was a Dialog pinned to the top left, under the switcher it opened from.
/// A panel gives it the full height of the window instead of a fraction of it,
/// which is the axis a tree of companies actually needs.
class ScopePickerPanel extends StatefulWidget {
  const ScopePickerPanel({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ScopePickerPanel> createState() => _ScopePickerPanelState();
}

/// One selectable row, flattened from the tree but keeping its depth and path.
class _Entry {
  const _Entry({
    required this.value,
    required this.label,
    required this.icon,
    required this.depth,
    required this.path,
    this.logoPath,
  });

  final String value;
  final String label;
  final IconData icon;
  final int depth;

  /// The ancestry, for searching and for showing which Connecticut this is.
  final String path;

  final String? logoPath;

  bool matches(String term) => path.toLowerCase().contains(term);
}

class _ScopePickerPanelState extends State<ScopePickerPanel> {
  final _search = TextEditingController();
  final _focus = FocusNode();

  @override
  void dispose() {
    _search.dispose();
    _focus.dispose();
    super.dispose();
  }

  /// The tree, flattened once, in the order it is drawn.
  List<_Entry> get _entries {
    final me = widget.session.me!;
    final out = <_Entry>[
      // Only for somebody assigned at organization level (2026-10-16).
      if (me.organization.canView)
        _Entry(
          value: 'organization',
          label: me.organization.name,
          icon: Icons.hub_outlined,
          depth: 0,
          path: me.organization.name,
          logoPath: '/branding/organization',
        ),
    ];

    // Switched-off companies and places are not offered (Jose, 2026-10-15).
    final companies = me.switchableCompanies;
    final places = me.switchableFranchises;

    void addCompany(CompanySummary company, int depth, String prefix) {
      final path = prefix.isEmpty ? company.name : '$prefix › ${company.name}';
      final subs = companies.where((c) => c.parentCompanyId == company.id);

      out.add(
        _Entry(
          value: 'company:${company.id}',
          label: company.name,
          icon: Icons.apartment_outlined,
          depth: depth,
          path: path,
          logoPath: '/branding/companies/${company.id}',
        ),
      );

      for (final f in places.where((f) => f.companyId == company.id)) {
        out.add(
          _Entry(
            value: 'franchise:${f.id}',
            label: f.name,
            icon: Icons.storefront_outlined,
            depth: depth + 1,
            path: '$path › ${f.name}',
          ),
        );
      }

      for (final sub in subs) {
        addCompany(sub, depth + 1, path);
      }
    }

    final reachableIds = companies.map((c) => c.id).toSet();
    for (final company in companies.where(
      (c) =>
          c.parentCompanyId == null ||
          !reachableIds.contains(c.parentCompanyId),
    )) {
      addCompany(company, 0, '');
    }

    return out;
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final term = _search.text.trim().toLowerCase();
    final all = _entries;

    final visible = term.isEmpty
        ? all
        : all.where((e) => e.matches(term)).toList();

    // A search box for five entries is a control that only gets in the way.
    // It earns its place once the list stops fitting in one glance.
    final searchable = all.length > 8;

    return SidePanel(
      // The dialog carried no title — it opened under the switcher, which was
      // the label. A panel opens away from it and has to say what it is.
      title: 'Switch view',
      width: 460,
      // The list scrolls itself, and has to: every company row carries a
      // LogoBadge that fetches its own bytes on first build. Inside the panel's
      // scroll view the list would be handed unbounded height and build all of
      // them, so opening the picker in a twenty-company organization would fire
      // twenty requests to draw the handful you can see. Which is the exact
      // scaling this panel exists to fix.
      scrollable: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (searchable) ...[
            TextField(
              key: const Key('scope-picker-search'),
              controller: _search,
              focusNode: _focus,
              autofocus: true,
              decoration: InputDecoration(
                hintText: 'Search companies and places',
                prefixIcon: const Icon(Icons.search, size: 20),
                isDense: true,
                border: const OutlineInputBorder(),
                suffixIcon: term.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.clear, size: 18),
                        tooltip: 'Clear the search',
                        onPressed: () => setState(_search.clear),
                      ),
              ),
              onChanged: (_) => setState(() {}),
              // Enter takes the first match, so a whole selection is
              // "type three letters, press return" without touching the
              // mouse.
              onSubmitted: (_) {
                if (visible.isNotEmpty) _select(visible.first.value);
              },
            ),
            const SizedBox(height: 8),
          ],

          if (visible.isEmpty)
            Padding(
              padding: const EdgeInsets.all(28),
              child: Text(
                'Nothing matches "${_search.text.trim()}".',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            )
          else
            Expanded(
              child: ListView.builder(
                itemCount: visible.length,
                itemBuilder: (context, i) => _row(
                  visible[i],
                  me,
                  // While searching, the indent means nothing — the parent may
                  // be filtered out — so the full path is shown instead and
                  // everything sits flush.
                  searching: term.isNotEmpty,
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _row(_Entry entry, Me me, {required bool searching}) {
    final scheme = Theme.of(context).colorScheme;
    final selected =
        '${me.scope.type}:${me.scope.id}' == entry.value ||
        (entry.value == 'organization' && me.scope.type == 'organization');

    return InkWell(
      onTap: () => _select(entry.value),
      child: Container(
        // Rounded, because the panel's own padding stops it short of both
        // edges — a square band clipped mid-air reads as a mistake, a pill
        // reads as a selection.
        decoration: selected
            ? BoxDecoration(
                color: scheme.primary.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(8),
              )
            : null,
        // The panel supplies the margin either side; only the indent is this
        // row's own business.
        padding: EdgeInsets.fromLTRB(
          8 + (searching ? 0 : entry.depth * 20.0),
          9,
          8,
          9,
        ),
        child: Row(
          children: [
            if (entry.logoPath != null)
              LogoBadge(
                api: widget.api,
                path: entry.logoPath!,
                hasOwn: false,
                mayEdit: false,
                bare: true,
                size: 18,
                fallbackIcon: entry.icon,
              )
            else
              Icon(
                entry.icon,
                size: 17,
                color: selected ? scheme.primary : scheme.onSurfaceVariant,
              ),
            const SizedBox(width: 11),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    entry.label,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                      color: selected ? scheme.primary : null,
                    ),
                  ),
                  // Only while searching. In the tree the indent already says
                  // it; in a filtered list nothing else can.
                  if (searching && entry.path != entry.label)
                    Text(
                      entry.path,
                      style: Theme.of(context).textTheme.bodySmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _select(String value) {
    Navigator.of(context).pop();
    widget.session.setScope(value);
  }
}

/// Opens the picker.
Future<void> showScopePicker(
  BuildContext context, {
  required ApiClient api,
  required Session session,
}) {
  return showSidePanel<void>(
    context,
    builder: (_) => ScopePickerPanel(api: api, session: session),
  );
}
