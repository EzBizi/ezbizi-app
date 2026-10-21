import 'session.dart';

/// One branch as a picker offers it: "Connecticut · Everdoors".
class BranchOption {
  const BranchOption({required this.branch, required this.label});

  final FranchiseSummary branch;

  /// "Branch · Company", or the branch name alone when its company is not
  /// among the companies given.
  final String label;

  String get id => branch.id;
}

/// The branches a picker offers, each named with its company.
///
/// Jose, 2026-10-21, on a picker reading "Main" twenty times and
/// "Connecticut" seven: a branch name alone does not say which one it is.
/// Every branch picker labels "Branch · Company" and sorts by company, then
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
            label: [f.name, ?byId[f.companyId]?.name].join(' · '),
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
