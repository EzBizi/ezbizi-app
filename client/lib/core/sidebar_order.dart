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
