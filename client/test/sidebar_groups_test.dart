import 'package:ezbizi_client/core/sidebar_order.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('rows move under their headings, personal order kept inside', () {
    final g = groupSidebar(
      ['hr', 'jobs', 'phone', 'dashboard', 'mystery'],
      {1},
      (s) => s,
    );
    expect(g.rows, ['jobs', 'dashboard', 'phone', 'hr', 'mystery']);
    expect(g.headingAt, {
      0: 'Work',
      2: 'Communication',
      3: 'People',
      4: 'System',
    });
    // The rule after "jobs" now sits between two Work rows: kept.
    expect(g.divideAfter, {0});
  });

  test('no module is lost', () {
    final mods = [for (final grp in sidebarGroups) ...grp.keys, 'new_one'];
    expect(groupSidebar(mods, {}, (s) => s).rows.toSet(), mods.toSet());
  });

  // Jose 11-24: sections are his. A saved order with headings is drawn as
  // saved; one without is the fixed groups.
  test('a saved order with headings draws those sections', () {
    final g = layoutSidebar(
      ['dashboard', 'jobs', 'phone', 'hr'],
      ['#Dispatch', 'phone', '--', 'jobs', '#Empty', '#Office', 'dashboard'],
      (s) => s,
    );
    // hr is not in the order: it joins the last section (no People section).
    expect(g.rows, ['phone', 'jobs', 'dashboard', 'hr']);
    expect(g.headingAt, {0: 'Dispatch', 2: 'Office'});
    expect(g.divideAfter, {0});
    expect(sidebarSectionsOf(g.rows, g.divideAfter, g.headingAt, (s) => s), [
      '#Dispatch',
      'phone',
      '--',
      'jobs',
      '#Office',
      'dashboard',
      'hr',
    ]);
  });

  test('a module missing from the order goes home to its group name', () {
    final g = layoutSidebar(
      ['jobs', 'hr', 'phone'],
      ['#People', 'jobs', '#Mine', 'phone'],
      (s) => s,
    );
    expect(g.rows, ['jobs', 'hr', 'phone']);
  });

  test('no headings saved: the fixed groups, written back with headings', () {
    final g = layoutSidebar(['phone', 'jobs'], const [], (s) => s);
    expect(g.headingAt, {0: 'Work', 1: 'Communication'});
    expect(sidebarSectionsOf(g.rows, g.divideAfter, g.headingAt, (s) => s), [
      '#Work',
      'jobs',
      '#Communication',
      'phone',
    ]);
  });
}
