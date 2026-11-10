import 'package:flutter/material.dart';

import '../core/branch_options.dart';
import '../core/session.dart';
import 'side_panel.dart';

/// "Where it applies": the organization, a company with its branches, or one
/// branch, chosen from one list grouped by company (UI queue 129).
///
/// Jose, 2026-10-22, on two flat runs of "Main — Ace Air Duct" and "Ace Air
/// Duct and everything under it": *"a bit ugly and confusing"*. So it reads
/// like the business: Entire organization, then each company in bold with its
/// branches indented under it, by name alone, because the company is already
/// above. A company with one branch is one row.
///
/// The value is the stored shape (`organization`, `company:<id>`,
/// `franchise:<id>`), the same string the callers already split into
/// `scope_type` and `scope_id`. What may be chosen is [places]; which kinds
/// those are is the caller's whole contract (see [scopeGroups]).
///
/// It opens a side panel, which on a phone is the full width of the screen.
class ScopeChoiceField extends StatelessWidget {
  const ScopeChoiceField({
    super.key,
    required this.value,
    required this.places,
    required this.companies,
    required this.branches,
    required this.onChanged,
    this.label,
    this.helperText,
    this.helperMaxLines,
    this.isDense = false,
    this.fallbackLabel,
  });

  final String? value;

  /// Value to the label it arrived with, in order.
  final Map<String, String> places;

  /// The person's reach, from `/me`, which names and groups [places].
  final List<CompanySummary> companies;
  final List<FranchiseSummary> branches;

  /// Null disables the field.
  final ValueChanged<String>? onChanged;

  final String? label;
  final String? helperText;
  final int? helperMaxLines;
  final bool isDense;

  /// Shown for a [value] neither [places] nor the reach can name.
  final String? fallbackLabel;

  String? get _chosen {
    final v = value;
    if (v == null) return null;
    return scopeLabel(v, companies, branches) ?? places[v] ?? fallbackLabel;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final enabled = onChanged != null;
    final chosen = _chosen;

    return InkWell(
      onTap: enabled ? () => _open(context) : null,
      borderRadius: BorderRadius.circular(8),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          helperText: helperText,
          helperMaxLines: helperMaxLines,
          isDense: isDense,
          enabled: enabled,
          suffixIcon: Icon(
            Icons.arrow_drop_down,
            color: enabled ? scheme.onSurfaceVariant : theme.disabledColor,
          ),
        ),
        isEmpty: chosen == null,
        child: chosen == null
            ? const SizedBox.shrink()
            : Text(
                chosen,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium,
              ),
      ),
    );
  }

  Future<void> _open(BuildContext context) async {
    final picked = await showSidePanel<String>(
      context,
      builder: (_) => ScopeChoicePanel(
        title: label ?? 'Where it applies',
        value: value,
        places: places,
        companies: companies,
        branches: branches,
      ),
    );

    if (picked != null) onChanged?.call(picked);
  }
}

/// The grouped list itself. Pops with the chosen value.
class ScopeChoicePanel extends StatefulWidget {
  const ScopeChoicePanel({
    super.key,
    required this.title,
    required this.value,
    required this.places,
    required this.companies,
    required this.branches,
  });

  final String title;
  final String? value;
  final Map<String, String> places;
  final List<CompanySummary> companies;
  final List<FranchiseSummary> branches;

  @override
  State<ScopeChoicePanel> createState() => _ScopeChoicePanelState();
}

class _ScopeChoicePanelState extends State<ScopeChoicePanel> {
  final _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  /// [ScopeChoicePanel.places] without switched-off companies and places
  /// (Jose, 2026-11-10), except the one already chosen.
  Map<String, String> get _places {
    final named = {
      for (final c in widget.companies) 'company:${c.id}',
      for (final f in widget.branches) 'franchise:${f.id}',
    };
    final on = {
      for (final c in switchedOnCompanies(widget.companies)) 'company:${c.id}',
      for (final f in switchedOnFranchises(widget.branches, widget.companies))
        'franchise:${f.id}',
    };
    return {
      for (final e in widget.places.entries)
        if (!named.contains(e.key) ||
            on.contains(e.key) ||
            e.key == widget.value)
          e.key: e.value,
    };
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final term = _search.text.trim();
    final places = _places;
    final all = scopeGroups(places, widget.companies, widget.branches);
    final groups = term.isEmpty
        ? all
        : scopeGroups(places, widget.companies, widget.branches, term: term);

    // A search box earns its place once the list stops fitting in one glance.
    final searchable = all.fold<int>(0, (n, g) => n + g.rows.length) >= 8;
    final phone = MediaQuery.sizeOf(context).width < 600;

    final rows = <Widget>[
      for (var i = 0; i < groups.length; i++) ...[
        if (i > 0) const Divider(height: 9),
        for (final row in groups[i].rows) _row(context, row),
      ],
    ];

    return SidePanel(
      title: widget.title,
      width: 460,
      scrollable: false,
      // On a phone it is the screen, so it reads as a page with a way back.
      onBack: phone ? () => Navigator.of(context).maybePop() : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (searchable) ...[
            TextField(
              key: const Key('scope-choice-search'),
              controller: _search,
              autofocus: !phone,
              decoration: InputDecoration(
                hintText: 'Search a company or branch',
                prefixIcon: const Icon(Icons.search, size: 20),
                isDense: true,
                suffixIcon: term.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.clear, size: 18),
                        tooltip: 'Clear the search',
                        onPressed: () => setState(_search.clear),
                      ),
              ),
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 8),
          ],
          if (groups.isEmpty)
            Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                'Nothing matches "$term".',
                style: theme.textTheme.bodyMedium,
              ),
            )
          else
            Expanded(
              child: ListView(
                key: const Key('scope-choice-list'),
                children: rows,
              ),
            ),
        ],
      ),
    );
  }

  Widget _row(BuildContext context, ScopeRow row) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final selected = row.covers.contains(widget.value);
    final head = !row.indented;

    final title = Text(
      row.title,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodyMedium?.copyWith(
        fontWeight: head || selected ? FontWeight.w700 : FontWeight.w500,
        color: selected
            ? scheme.primary
            : row.off
            ? scheme.onSurfaceVariant
            : scheme.onSurface,
      ),
    );

    final body = Container(
      key: ValueKey(
        row.selectable
            ? 'scope-choice-${row.value}'
            : 'scope-choice-head-${row.title}',
      ),
      decoration: selected
          ? BoxDecoration(
              color: scheme.primary.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(8),
            )
          : null,
      padding: EdgeInsets.fromLTRB(row.indented ? 52 : 8, 9, 8, 9),
      child: Row(
        children: [
          if (head) ...[
            Icon(
              row.value == 'organization'
                  ? Icons.hub_outlined
                  : Icons.apartment_outlined,
              size: 17,
              color: selected ? scheme.primary : scheme.onSurfaceVariant,
            ),
            const SizedBox(width: 11),
          ],
          Expanded(child: title),
          if (row.off) ...[
            const SizedBox(width: 8),
            Text(
              'off',
              style: theme.textTheme.labelSmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
          ],
          if (row.trailing != null) ...[
            const SizedBox(width: 8),
            Text(
              row.trailing!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
          ],
          if (selected) ...[
            const SizedBox(width: 8),
            Icon(Icons.check, size: 18, color: scheme.primary),
          ],
        ],
      ),
    );

    if (!row.selectable) return body;

    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: () => Navigator.of(context).pop(row.value),
      child: body,
    );
  }
}
