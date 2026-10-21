import 'package:flutter/material.dart';


import '../core/branch_options.dart';
import '../core/session.dart';
import '../widgets/search_picker.dart';

/// Picking a place, when several of them share a name (plan §3).
///
/// ── The problem this exists for ──────────────────────────────────
///
/// Every franchise picker in the app was a dropdown of bare names, and the
/// business it is being built for has four companies operating in overlapping
/// territories. So the list read:
///
///     Connecticut · DMV · Georgia · Illinois · New Jersey · Ohio · Texas ·
///     Washington · Nationwide · Connecticut · DMV · Connecticut · Connecticut
///
/// Four Connecticuts and two DMVs, in one flat run, with nothing to tell them
/// apart. Whichever you pick, the record is owned by a franchise you did not
/// choose on purpose — and owning-franchise is not cosmetic: it decides whose
/// numbers a customer counts in, and §5.2 makes changing it a separate audited
/// step rather than a correction.
///
/// The scope switcher already solved this by showing the ancestry. It was fixed
/// there and nowhere else, because it was treated as a bug in that screen rather
/// than as a fact about the data — so this is the shared version, and every
/// picker takes it.
///
/// ── Why the whole path rather than just the company ──────────────
///
/// "Everdoors › Connecticut" is enough today. It will not be: Premier Garage
/// Repair and Speedy Garage Doors are Sub Companies of Everdoors (§3), and both
/// can hold a Connecticut. Naming only the immediate parent would produce two
/// identical "Premier Garage Repair › Connecticut" rows the moment a second
/// company does the same thing, which is the bug again with more words.
String franchisePath(Me me, FranchiseSummary franchise) {
  final parts = <String>[franchise.name];

  var companyId = franchise.companyId;

  // Upward through the tree until there is no parent. §3 fixes the depth at
  // Organization → Company → Sub Company → Franchise, so this terminates in at
  // most two steps — the guard is for data that disagrees with the plan, not
  // for a depth the plan allows.
  final seen = <String>{};

  while (companyId != null && seen.add(companyId)) {
    final company = me.companies.where((c) => c.id == companyId).firstOrNull;

    if (company == null) break;

    parts.insert(0, company.name);
    companyId = company.parentCompanyId;
  }

  return parts.join(' › ');
}

/// A franchise dropdown that says which one it is.
///
/// A dropdown rather than the searchable panel the scope switcher uses: this
/// sits inside a form beside other fields, and a control that opens a panel over
/// the form somebody is filling in loses the rest of it. The switcher chooses
/// where you are, which is worth a screen; this chooses one field.
class FranchiseField extends StatelessWidget {
  const FranchiseField({
    super.key,
    required this.me,
    required this.value,
    required this.onChanged,
    this.label,
    this.helperText,
    this.franchises,
    this.enabled = true,
  });

  final Me me;
  final String? value;
  final ValueChanged<String?>? onChanged;

  /// Defaults to the organization's own word for a franchise (§12).
  final String? label;

  final String? helperText;

  /// A narrower list than the current view — the importer offers only the
  /// franchises the caller may write to. Defaults to [Me.franchisesInView].
  final List<FranchiseSummary>? franchises;

  final bool enabled;

  @override
  Widget build(BuildContext context) {
    // "Connecticut · Everdoors", sorted by company then branch, so the two
    // Connecticuts sit under their own companies rather than next to each
    // other: the one order every branch picker uses (Jose, 2026-10-21).
    // Switched-off places stay: a record may already be filed on one.
    final sorted = branchOptions(
      franchises ?? me.franchisesInView,
      me.companies,
      activeOnly: false,
    );

    // Only when it earns its place. In an organization with one company the
    // suffix is the same on every row, so it is noise that pushes the name
    // itself off the edge of a narrow field.
    final ambiguous = sorted.map((f) => f.branch.companyId).toSet().length > 1;

    return SearchPickerField<String>(
             value: value,
             label: label ?? me.organization.franchiseLabel,
             helperText: helperText,
             helperMaxLines: 3,
             options: [
        for (final f in sorted)
          SearchOption(value: f.id, label: ambiguous ? f.label : f.branch.name),
      ],
             onChanged: enabled ? onChanged : null,
           );
  }
}
