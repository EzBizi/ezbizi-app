import 'package:flutter/material.dart';
import '../core/session.dart';

/// Which part of the business you are looking at (plan §3 roll-ups).
///
/// ── What choosing one actually does ──────────────────────────────
///
/// It narrows everything at once — the dashboard's numbers, the lists, what an
/// export contains — and it decides where new work is created. Somebody
/// standing in Connecticut cannot book a job in Texas, because from there Texas
/// does not exist.
///
/// A company includes its Sub Companies; a Sub Company never includes its
/// parent. Roll-ups go up.
///
/// ── Hidden when there is nothing to choose ───────────────────────
///
/// Somebody who can reach exactly one franchise does not get a switcher — the
/// app simply IS that franchise for them. A control with one option is a
/// control that only teaches people it does nothing.
class ScopeSwitcher extends StatelessWidget {
  const ScopeSwitcher({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    final me = session.me!;

    // One franchise and no company to roll up into: there is nowhere else to
    // stand.
    if (me.franchises.length <= 1 && me.companies.length <= 1) {
      return const SizedBox.shrink();
    }

    final scheme = Theme.of(context).colorScheme;

    return PopupMenuButton<String>(
      tooltip: 'Change what you are looking at',
      position: PopupMenuPosition.under,
      onSelected: session.setScope,
      itemBuilder: (context) => [
        PopupMenuItem(
          value: 'organization',
          child: _Entry(
            icon: Icons.hub_outlined,
            label: me.organization.name,
            note: 'Everything',
            selected: me.scope.type == 'organization',
            depth: 0,
          ),
        ),
        const PopupMenuDivider(),

        // Top-level companies, each followed by its own Sub Companies and the
        // franchises beneath both — the same tree the structure screen draws,
        // because it is the same tree.
        for (final company in me.companies.where((c) => c.parentCompanyId == null)) ...[
          _companyItem(me, company, depth: 0),
          for (final f in me.franchises.where((f) => f.companyId == company.id))
            _franchiseItem(me, f, depth: 1),
          for (final sub in me.companies.where((c) => c.parentCompanyId == company.id)) ...[
            _companyItem(me, sub, depth: 1),
            for (final f in me.franchises.where((f) => f.companyId == sub.id))
              _franchiseItem(me, f, depth: 2),
          ],
        ],
      ],
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        decoration: BoxDecoration(
          color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
          borderRadius: BorderRadius.circular(9),
          border: Border.all(color: scheme.outlineVariant),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(_iconFor(me.scope.type), size: 15, color: scheme.onSurfaceVariant),
          const SizedBox(width: 8),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 220),
            child: Text(
              me.scope.label,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600),
            ),
          ),
          const SizedBox(width: 6),
          Icon(Icons.unfold_more, size: 15, color: scheme.onSurfaceVariant),
        ]),
      ),
    );
  }

  PopupMenuItem<String> _companyItem(Me me, CompanySummary company, {required int depth}) {
    return PopupMenuItem(
      value: 'company:${company.id}',
      child: _Entry(
        icon: Icons.apartment_outlined,
        label: company.name,
        // Said out loud, because it is the rule people get wrong: a company
        // view includes everything under its Sub Companies too.
        note: me.companies.any((c) => c.parentCompanyId == company.id)
            ? 'and its sub companies'
            : null,
        selected: me.scope.type == 'company' && me.scope.id == company.id,
        depth: depth,
      ),
    );
  }

  PopupMenuItem<String> _franchiseItem(Me me, FranchiseSummary f, {required int depth}) {
    return PopupMenuItem(
      value: 'franchise:${f.id}',
      child: _Entry(
        icon: Icons.storefront_outlined,
        label: f.name,
        selected: me.scope.type == 'franchise' && me.scope.id == f.id,
        depth: depth,
      ),
    );
  }

  static IconData _iconFor(String type) => switch (type) {
        'company' => Icons.apartment_outlined,
        'franchise' => Icons.storefront_outlined,
        _ => Icons.hub_outlined,
      };
}

class _Entry extends StatelessWidget {
  const _Entry({
    required this.icon,
    required this.label,
    required this.selected,
    required this.depth,
    this.note,
  });

  final IconData icon;
  final String label;
  final String? note;
  final bool selected;
  final int depth;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      // The indent is the hierarchy, the same as everywhere else this tree is
      // drawn. A flat menu would say a Sub Company is a sibling of its parent.
      padding: EdgeInsets.only(left: depth * 18.0),
      child: Row(children: [
        Icon(icon,
            size: 16,
            color: selected ? scheme.primary : scheme.onSurfaceVariant),
        const SizedBox(width: 10),
        Flexible(
          child: Text(
            label,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontWeight: selected ? FontWeight.w700 : FontWeight.w400,
              color: selected ? scheme.primary : null,
            ),
          ),
        ),
        if (note != null) ...[
          const SizedBox(width: 10),
          Text(note!, style: Theme.of(context).textTheme.bodySmall),
        ],
      ]),
    );
  }
}
