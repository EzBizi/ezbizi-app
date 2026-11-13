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
}
