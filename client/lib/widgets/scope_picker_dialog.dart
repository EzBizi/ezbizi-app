import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import 'logo_badge.dart';

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
class ScopePickerDialog extends StatefulWidget {
  const ScopePickerDialog({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ScopePickerDialog> createState() => _ScopePickerDialogState();
}

/// One selectable row, flattened from the tree but keeping its depth and path.
class _Entry {
  const _Entry({
    required this.value,
    required this.label,
    required this.icon,
    required this.depth,
    required this.path,
    this.note,
    this.logoPath,
  });

  final String value;
  final String label;
  final IconData icon;
  final int depth;

  /// The ancestry, for searching and for showing which Connecticut this is.
  final String path;

  final String? note;
  final String? logoPath;

  bool matches(String term) => path.toLowerCase().contains(term);
}

class _ScopePickerDialogState extends State<ScopePickerDialog> {
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
      _Entry(
        value: 'organization',
        label: me.organization.name,
        icon: Icons.hub_outlined,
        depth: 0,
        path: me.organization.name,
        note: 'Everything',
        logoPath: '/branding/organization',
      ),
    ];

    void addCompany(CompanySummary company, int depth, String prefix) {
      final path = prefix.isEmpty ? company.name : '$prefix › ${company.name}';
      final subs = me.companies.where((c) => c.parentCompanyId == company.id);

      out.add(_Entry(
        value: 'company:${company.id}',
        label: company.name,
        icon: Icons.apartment_outlined,
        depth: depth,
        path: path,
        note: subs.isNotEmpty ? 'and its sub companies' : null,
        logoPath: '/branding/companies/${company.id}',
      ));

      for (final f in me.franchises.where((f) => f.companyId == company.id)) {
        out.add(_Entry(
          value: 'franchise:${f.id}',
          label: f.name,
          icon: Icons.storefront_outlined,
          depth: depth + 1,
          path: '$path › ${f.name}',
        ));
      }

      for (final sub in subs) {
        addCompany(sub, depth + 1, path);
      }
    }

    for (final company in me.companies.where((c) => c.parentCompanyId == null)) {
      addCompany(company, 0, '');
    }

    return out;
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final term = _search.text.trim().toLowerCase();
    final all = _entries;

    final visible =
        term.isEmpty ? all : all.where((e) => e.matches(term)).toList();

    // A search box for five entries is a control that only gets in the way.
    // It earns its place once the list stops fitting in one glance.
    final searchable = all.length > 8;

    return Dialog(
      alignment: Alignment.topLeft,
      insetPadding: const EdgeInsets.only(left: 56, top: 64, right: 24, bottom: 24),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxWidth: 460,
          // Capped against the WINDOW, not a fixed number: the list has to stay
          // inside the screen on a laptop and may as well use the room on a
          // large monitor.
          maxHeight: MediaQuery.sizeOf(context).height * 0.7,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (searchable)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 8),
                child: TextField(
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
              ),

            Flexible(
              child: visible.isEmpty
                  ? Padding(
                      padding: const EdgeInsets.all(28),
                      child: Text('Nothing matches "${_search.text.trim()}".',
                          style: Theme.of(context).textTheme.bodyMedium),
                    )
                  : ListView.builder(
                      shrinkWrap: true,
                      padding: const EdgeInsets.only(bottom: 8),
                      itemCount: visible.length,
                      itemBuilder: (context, i) => _row(
                        visible[i],
                        me,
                        // While searching, the indent means nothing — the
                        // parent may be filtered out — so the full path is
                        // shown instead and everything sits flush.
                        searching: term.isNotEmpty,
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(_Entry entry, Me me, {required bool searching}) {
    final scheme = Theme.of(context).colorScheme;
    final selected = '${me.scope.type}:${me.scope.id}' == entry.value ||
        (entry.value == 'organization' && me.scope.type == 'organization');

    return InkWell(
      onTap: () => _select(entry.value),
      child: Container(
        color: selected ? scheme.primary.withValues(alpha: 0.10) : null,
        padding: EdgeInsets.fromLTRB(
          14 + (searching ? 0 : entry.depth * 20.0),
          9,
          14,
          9,
        ),
        child: Row(children: [
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
            Icon(entry.icon,
                size: 17,
                color: selected ? scheme.primary : scheme.onSurfaceVariant),
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
                  Text(entry.path,
                      style: Theme.of(context).textTheme.bodySmall,
                      overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          if (entry.note != null) ...[
            const SizedBox(width: 10),
            Text(entry.note!, style: Theme.of(context).textTheme.bodySmall),
          ],
        ]),
      ),
    );
  }

  void _select(String value) {
    Navigator.of(context).pop();
    widget.session.setScope(value);
  }
}

/// Opens the picker. Escape closes it, like every other dialog.
Future<void> showScopePicker(
  BuildContext context, {
  required ApiClient api,
  required Session session,
}) {
  return showDialog<void>(
    context: context,
    builder: (_) => Shortcuts(
      shortcuts: const {SingleActivator(LogicalKeyboardKey.escape): DismissIntent()},
      child: ScopePickerDialog(api: api, session: session),
    ),
  );
}
