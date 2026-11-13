/// The saved sidebar order, applied to the modules somebody actually has.
///
/// The order is a list of module keys with [sidebarDivider] between whichever
/// of them this person wanted separated. It is stored per user
/// (`navigation.order`), so by the time this build reads one it can easily
/// describe a sidebar that no longer exists: a module switched off under them,
/// one their role stopped reaching, or one this app has never heard of because
/// the server is newer. All three are ordinary, and none of them may lose a
/// module — an order is a preference about ARRANGEMENT, and nothing in it can
/// be allowed to decide what is in the sidebar at all.
library;

/// The one entry in a saved order that is not a module key.
///
/// Deliberately not a module-shaped word: keys are bare words (`jobs`, `hr`),
/// so this cannot collide with one now or with a module added later.
const String sidebarDivider = '--';

/// [modules] rearranged into [order], and where the rules go.
///
/// `rows` is every module that was passed in, without exception — the ones the
/// order names, in its order, then the ones it does not, in theirs. A module
/// added since this person last arranged their sidebar is NEW, not rejected,
/// and appears at the end rather than vanishing.
///
/// `divideAfter` holds positions in `rows`, so a caller can draw its own rule
/// in its own way. Three things it never contains, because a rule has to
/// separate two things to mean anything: a position before the first row, one
/// after the last, and the same position twice.
({List<T> rows, Set<int> divideAfter}) arrangeSidebar<T>(
  List<T> modules,
  List<String> order,
  String Function(T) idOf,
) {
  final byId = {for (final m in modules) idOf(m): m};
  final rows = <T>[];
  final divideAfter = <int>{};
  final placed = <String>{};

  for (final entry in order) {
    if (entry == sidebarDivider) {
      // Nothing above it yet means a leading rule, which is a line under the
      // search field. A Set means the second of two touching rules is the
      // first one again.
      if (rows.isNotEmpty) divideAfter.add(rows.length - 1);
      continue;
    }
    final module = byId[entry];
    if (module == null || !placed.add(entry)) continue;
    rows.add(module);
  }

  for (final module in modules) {
    if (!placed.contains(idOf(module))) rows.add(module);
  }

  // A rule under the last row draws along the bottom of the list, where it
  // reads as the sidebar's own edge rather than as a break inside it.
  divideAfter.remove(rows.length - 1);

  return (rows: rows, divideAfter: divideAfter);
}

/// The inverse: [rows] and [divideAfter] written back as a saveable order.
List<String> sidebarOrderOf<T>(
  List<T> rows,
  Set<int> divideAfter,
  String Function(T) idOf,
) => [
  for (var i = 0; i < rows.length; i++) ...[
    idOf(rows[i]),
    if (divideAfter.contains(i)) sidebarDivider,
  ],
];

/// The sidebar's fixed headings, in order, and the modules under each
/// (Jose 11-13, Muse audit option 1A). A person's own order still decides the
/// order INSIDE a group; the groups themselves do not move. A module not
/// listed here (new, or unknown to this build) goes under the last heading
/// rather than vanishing.
const List<({String label, List<String> keys})> sidebarGroups = [
  (
    label: 'Work',
    keys: [
      'dashboard',
      'jobs',
      'project_board',
      'scheduling',
      'field',
      'inspection',
    ],
  ),
  (
    label: 'Customers',
    keys: [
      'customers',
      'leads',
      'customer_experience',
      'reviews',
      'financials',
    ],
  ),
  (
    label: 'Communication',
    keys: [
      'phone',
      'email',
      'marketing',
      'web_management',
      'business_profile',
      'ads_performance',
      'ads_manager',
    ],
  ),
  (
    label: 'Operations',
    keys: ['services', 'inventory', 'fleet_management', 'tasks', 'company'],
  ),
  (label: 'People', keys: ['hr', 'work_hours', 'training', 'guidelines']),
  (
    label: 'System',
    keys: [
      'reports',
      'profit_loss',
      'monitoring',
      'automations',
      'ai_assistance',
    ],
  ),
];

int _groupOf(String id) {
  for (var g = 0; g < sidebarGroups.length; g++) {
    if (sidebarGroups[g].keys.contains(id)) return g;
  }
  return sidebarGroups.length - 1;
}

/// [rows] moved under their headings, keeping their order inside each group
/// and every rule that still sits between two rows of the same group.
/// `headingAt` maps a position in the result to the heading drawn above it.
({List<T> rows, Set<int> divideAfter, Map<int, String> headingAt})
groupSidebar<T>(List<T> rows, Set<int> divideAfter, String Function(T) idOf) {
  final indexed = [for (var i = 0; i < rows.length; i++) i];
  // List.sort is not stable; sort by (group, original position).
  indexed.sort((a, b) {
    final g = _groupOf(idOf(rows[a])).compareTo(_groupOf(idOf(rows[b])));
    return g != 0 ? g : a.compareTo(b);
  });

  final out = [for (final i in indexed) rows[i]];
  final outDivide = <int>{};
  final headingAt = <int, String>{};
  for (var p = 0; p < indexed.length; p++) {
    final g = _groupOf(idOf(out[p]));
    if (p == 0 || _groupOf(idOf(out[p - 1])) != g) {
      headingAt[p] = sidebarGroups[g].label;
    }
    final last = p == indexed.length - 1;
    if (!last &&
        divideAfter.contains(indexed[p]) &&
        _groupOf(idOf(out[p + 1])) == g) {
      outDivide.add(p);
    }
  }

  return (rows: out, divideAfter: outDivide, headingAt: headingAt);
}
