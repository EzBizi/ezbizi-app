import 'session.dart';

/// One branch as a picker offers it: "Everdoors · Connecticut".
class BranchOption {
  const BranchOption({required this.branch, required this.label});

  final FranchiseSummary branch;

  /// "Company · Branch", or the branch name alone when its company is not
  /// among the companies given.
  final String label;

  String get id => branch.id;
}

/// The branches a picker offers, each named with its company.
///
/// Jose, 2026-10-21, on a picker reading "Main" twenty times and
/// "Connecticut" seven: a branch name alone does not say which one it is.
/// Every branch picker labels "Company · Branch" (Jose, 2026-10-21: "like
/// a-1chimney texas") and sorts by company, then
/// branch, so a company's branches sit together.
///
/// [companyId] limits the list to that company and its Sub Companies. Only
/// active branches are offered unless [activeOnly] is false (a form whose
/// record may already sit on a switched-off branch).
List<BranchOption> branchOptions(
  List<FranchiseSummary> branches,
  List<CompanySummary> companies, {
  String? companyId,
  bool activeOnly = true,
}) {
  final byId = {for (final c in companies) c.id: c};

  bool under(String? id) {
    if (companyId == null) return true;
    final seen = <String>{};
    while (id != null && seen.add(id)) {
      if (id == companyId) return true;
      id = byId[id]?.parentCompanyId;
    }
    return false;
  }

  final rows = [
    for (final f in branches)
      if ((f.isActive || !activeOnly) && under(f.companyId))
        (
          company: byId[f.companyId]?.name,
          option: BranchOption(
            branch: f,
            label: [?byId[f.companyId]?.name, f.name].join(' · '),
          ),
        ),
  ];

  int text(String a, String b) => a.toLowerCase().compareTo(b.toLowerCase());
  rows.sort((a, b) {
    // A branch whose company is unknown goes after the named ones.
    final ca = a.company, cb = b.company;
    if (ca != cb) {
      if (ca == null) return 1;
      if (cb == null) return -1;
      final byCompany = text(ca, cb);
      if (byCompany != 0) return byCompany;
    }
    return text(a.option.branch.name, b.option.branch.name);
  });

  return [for (final r in rows) r.option];
}

/// [branchOptions] for the current view: [Me.franchisesInView], named from
/// [Me.companies] (a branch in view can sit under a parent outside it).
List<BranchOption> branchOptionsInView(
  Me me, {
  String? companyId,
  bool activeOnly = true,
}) => branchOptions(
  me.franchisesInView,
  me.companies,
  companyId: companyId,
  activeOnly: activeOnly,
);

// ── "Where it applies": one list grouped by company (UI queue 129) ──

/// One row of the grouped scope list.
///
/// [value] is what choosing the row sends (`organization`, `company:<id>`,
/// `franchise:<id>`), or null for a company drawn only as a header because
/// the caller takes branches alone.
class ScopeRow {
  const ScopeRow({
    required this.title,
    required this.value,
    this.trailing,
    this.off = false,
    this.indented = false,
    this.covers = const {},
  });

  final String title;
  final String? value;

  /// "4 branches", "1 branch", "every company".
  final String? trailing;

  /// Switched off: greyed and marked "off". Still chosen like any other row,
  /// because the caller offered it.
  final bool off;

  /// A branch under its company.
  final bool indented;

  /// Every value this row stands for, so the current choice is highlighted
  /// even when a single-branch company row was saved as its branch.
  final Set<String> covers;

  bool get selectable => value != null;
}

/// A company with its branches, or the organization row on its own. Groups are
/// drawn with a divider between each.
class ScopeGroup {
  const ScopeGroup(this.head, [this.branches = const []]);

  final ScopeRow head;
  final List<ScopeRow> branches;

  List<ScopeRow> get rows => [head, ...branches];
}

String _count(int n) => n == 1 ? '1 branch' : '$n branches';

int _text(String a, String b) => a.toLowerCase().compareTo(b.toLowerCase());

/// The grouped list for a scope picker.
///
/// [places] is what the caller may choose, keyed by value in the stored shape,
/// each with the label to fall back on when [companies] and [branches] (the
/// person's reach, from `/me`) do not name it. The caller's needs are read off
/// the keys: no `company:` key means branches only (companies become headers),
/// no `franchise:` key means companies only (no branch rows).
///
/// [term] narrows it: a company that matches keeps all its branches, a branch
/// that matches keeps its company above it.
List<ScopeGroup> scopeGroups(
  Map<String, String> places,
  List<CompanySummary> companies,
  List<FranchiseSummary> branches, {
  String term = '',
}) {
  final needle = term.trim().toLowerCase();
  bool hit(String text) =>
      needle.isEmpty || text.toLowerCase().contains(needle);

  final takesCompanies = places.keys.any((k) => k.startsWith('company:'));
  final takesBranches = places.keys.any((k) => k.startsWith('franchise:'));
  final companyById = {for (final c in companies) c.id: c};
  final placed = <String>{};

  final groups = <ScopeGroup>[];

  if (places.containsKey('organization') && hit('Entire organization')) {
    groups.add(
      const ScopeGroup(
        ScopeRow(
          title: 'Entire organization',
          value: 'organization',
          trailing: 'every company',
          covers: {'organization'},
        ),
      ),
    );
  }
  placed.add('organization');

  // Branches offered, by company.
  final offered = <String, List<FranchiseSummary>>{};
  for (final f in branches) {
    if (!takesBranches) {
      if (f.companyId != null) (offered[f.companyId!] ??= []).add(f);
    } else if (places.containsKey('franchise:${f.id}') &&
        companyById.containsKey(f.companyId)) {
      (offered[f.companyId!] ??= []).add(f);
    }
  }
  for (final list in offered.values) {
    list.sort((a, b) => _text(a.name, b.name));
  }

  bool shown(CompanySummary c) =>
      places.containsKey('company:${c.id}') ||
      (takesBranches && (offered[c.id]?.isNotEmpty ?? false));

  // Depth first, by name, so a Sub Company follows its parent.
  final ordered = <CompanySummary>[];
  final seen = <String>{};
  void walk(CompanySummary c) {
    if (!seen.add(c.id)) return;
    ordered.add(c);
    for (final sub
        in companies.where((s) => s.parentCompanyId == c.id).toList()
          ..sort((a, b) => _text(a.name, b.name))) {
      walk(sub);
    }
  }

  for (final root
      in companies
          .where(
            (c) =>
                c.parentCompanyId == null ||
                !companyById.containsKey(c.parentCompanyId),
          )
          .toList()
        ..sort((a, b) => _text(a.name, b.name))) {
    walk(root);
  }

  for (final c in ordered) {
    if (!shown(c)) continue;
    final companyKey = 'company:${c.id}';
    final own = offered[c.id] ?? const <FranchiseSummary>[];
    final hasSubs = ordered.any((s) => s.parentCompanyId == c.id && shown(s));
    final companyValue = takesCompanies && places.containsKey(companyKey)
        ? companyKey
        : null;
    placed.add(companyKey);
    for (final f in own) {
      placed.add('franchise:${f.id}');
    }

    // ONE BRANCH, ONE ROW: no lone "Main" under its company.
    if (own.length == 1 && !hasSubs) {
      final only = own.single;
      if (!hit(c.name) && !hit(only.name)) continue;
      final branchKey = 'franchise:${only.id}';
      groups.add(
        ScopeGroup(
          ScopeRow(
            title: c.name,
            value: companyValue ?? (takesBranches ? branchKey : null),
            trailing: _count(1),
            off: !c.isActive || !only.isActive,
            covers: {companyKey, branchKey},
          ),
        ),
      );
      continue;
    }

    final companyHit = hit(c.name);
    final rows = [
      if (takesBranches)
        for (final f in own)
          if (companyHit || hit(f.name))
            ScopeRow(
              title: f.name,
              value: 'franchise:${f.id}',
              off: !f.isActive,
              indented: true,
              covers: {'franchise:${f.id}'},
            ),
    ];
    if (!companyHit && rows.isEmpty) continue;

    groups.add(
      ScopeGroup(
        ScopeRow(
          title: c.name,
          value: companyValue,
          trailing: own.isEmpty ? 'no branches' : _count(own.length),
          off: !c.isActive,
          covers: {companyKey},
        ),
        rows,
      ),
    );
  }

  // Anything the reach does not name keeps the label it came with.
  final rest = [
    for (final e in places.entries)
      if (!placed.contains(e.key) && hit(e.value))
        ScopeRow(title: e.value, value: e.key, covers: {e.key}),
  ];
  for (final row in rest) {
    groups.add(ScopeGroup(row));
  }

  return groups;
}

/// What a chosen scope is called everywhere it is repeated: "Entire
/// organization", "Ace Air Duct (all branches)", "Ace Air Duct · Main".
/// Null when the reach does not name it, so the caller can fall back.
String? scopeLabel(
  String? value,
  List<CompanySummary> companies,
  List<FranchiseSummary> branches,
) {
  if (value == null) return null;
  if (value == 'organization') return 'Entire organization';

  final parts = value.split(':');
  if (parts.length != 2) return null;
  final [kind, id] = parts;

  if (kind == 'company') {
    for (final c in companies) {
      if (c.id == id) return '${c.name} (all branches)';
    }
    return null;
  }

  if (kind == 'franchise') {
    for (final f in branches) {
      if (f.id != id) continue;
      for (final c in companies) {
        if (c.id == f.companyId) return '${c.name} · ${f.name}';
      }
      return f.name;
    }
  }

  return null;
}

/// "Company · Branch" for a branch the server named by id, as every picker
/// labels it; [fallback] (the server's own name) when `/me` does not reach it.
String branchLabelOf(Me? me, String? id, String fallback) {
  if (me == null || id == null) return fallback;
  return scopeLabel('franchise:$id', me.companies, me.franchises) ?? fallback;
}
