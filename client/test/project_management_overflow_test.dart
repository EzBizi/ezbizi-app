import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Jose, 2026-10-22: "overflow issue as well" / "same on all the other tabs".
/// Dozens of service areas ran past the screen edge on Dashboard and Jobs.
/// Every Project Management tab, at 1440 and 390 wide, with 30 areas across
/// two companies that each have a "Main": nothing overflows, past six areas
/// the chips are one picker, and it sets service_area_id.
/// Every name invented.
void main() {
  final areas = [
    for (var i = 1; i <= 30; i++)
      {
        'id': 'sa-$i',
        'name': 'Area $i',
        'franchise_id': i.isEven ? 'fr-1' : 'fr-2',
      },
  ];
  const me = {
    'companies': [
      {'id': 'co-1', 'name': 'Company A', 'parent_company_id': null},
      {'id': 'co-2', 'name': 'Company B', 'parent_company_id': null},
    ],
    'franchises': [
      {'id': 'fr-1', 'name': 'Main', 'company_id': 'co-1'},
      {'id': 'fr-2', 'name': 'Main', 'company_id': 'co-2'},
    ],
  };

  Future<PmServer> open(WidgetTester tester, Size size, String tab) async {
    final server = PmServer(serviceAreas: areas, meOverride: me);
    await server.pump(tester, size: size);
    // Reports and Table are one tab since design 144: Repair team.
    final strip = tab == 'Reports' || tab == 'Table' ? 'Repair team' : tab;
    if (strip != 'Overview') {
      await tester.ensureVisible(find.text(strip).first);
      await tester.pumpAndSettle();
      await tester.tap(find.text(strip).first);
      await tester.pumpAndSettle();
    }
    // On a phone the place and the tab's filters wait behind Filters.
    final filters = find.byKey(const ValueKey('pm-frame-filters-button'));
    if (size.width < 600 && filters.evaluate().isNotEmpty) {
      await tester.tap(filters);
      await tester.pumpAndSettle();
    }
    if (tab == 'Table') {
      await tester.tap(find.byKey(const ValueKey('pm-repair-table')));
      await tester.pumpAndSettle();
    }
    // Proof the tab opened, so a phone width is not measured on Dashboard.
    final marker = switch (tab) {
      'Jobs' => 'tour-pm-jobs-stages',
      _ => null,
    };
    if (marker != null) expect(find.byKey(ValueKey(marker)), findsOneWidget);
    return server;
  }

  for (final size in const [
    Size(1440, 1000),
    Size(1024, 1000),
    Size(390, 1000),
  ]) {
    for (final tab in const [
      'Overview',
      'Jobs',
      'Assignments',
      'Reports',
      'Table',
      'Rules',
      'Logs',
      'Settings',
    ]) {
      testWidgets('$tab at ${size.width.toInt()} wide does not overflow', (
        tester,
      ) async {
        await open(tester, size, tab);
        // A RenderFlex overflow is reported as an exception.
        expect(tester.takeException(), isNull);
        // The scope bar is on every tab (design 144's frame).
        expect(find.byKey(const ValueKey('pm-scope-line')), findsOneWidget);
        if (tab == 'Overview' || tab == 'Jobs') {
          expect(find.byKey(const ValueKey('pm-area-picker')), findsOneWidget);
          expect(find.byKey(const ValueKey('pm-area-sa-1')), findsNothing);
        }
      });
    }
  }

  for (final tab in const ['Overview', 'Jobs']) {
    testWidgets('$tab: the area picker sets service_area_id', (tester) async {
      final server = await open(tester, const Size(1440, 1000), tab);
      await tester.tap(find.byKey(const ValueKey('pm-area-picker')));
      await tester.pumpAndSettle();
      final dialog = find.byType(SidePanel);
      await tester.enterText(
        find.descendant(of: dialog, matching: find.byType(TextField)),
        'Area 17',
      );
      await tester.pumpAndSettle();
      await tester.tap(
        find.descendant(of: dialog, matching: find.text('Area 17')).last,
      );
      await tester.pumpAndSettle();
      final path = tab == 'Jobs'
          ? '/project-management/jobs'
          : '/project-management/overview';
      expect(server.get(path).last.queryParameters['service_area_id'], 'sa-17');
    });
  }

  testWidgets('Reports: two companies\' "Main" read Company · Main', (
    tester,
  ) async {
    await open(tester, const Size(1440, 1000), 'Reports');
    await tester.tap(find.byKey(const ValueKey('pm-report-branch')));
    await tester.pumpAndSettle();
    final dialog = find.byType(SidePanel);
    expect(
      find.descendant(of: dialog, matching: find.text('Company A · Main')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: dialog, matching: find.text('Company B · Main')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: dialog, matching: find.text('Main')),
      findsNothing,
    );
  });

  for (final tab in const ['Overview', 'Jobs', 'Assignments', 'Rules']) {
    testWidgets('$tab: the scope line says which jobs and opens Settings', (
      tester,
    ) async {
      await open(tester, const Size(1440, 1000), tab);
      expect(
        find.text(
          'Counting jobs in any status · Brightway · set at Brightway Chimney',
        ),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('pm-scope-change')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('tour-pm-settings-scope')),
        findsOneWidget,
      );
    });
  }

  testWidgets(
    'a branch is named "Company · Branch" on the scope line and Settings',
    (tester) async {
      final server = PmServer(
        serviceAreas: areas,
        meOverride: me,
        scope: const {
          'type': 'franchise',
          'id': 'fr-1',
          'label': 'Main',
          'path': ['Company A', 'Main'],
        },
      );
      await server.pump(tester, size: const Size(1440, 1000));
      expect(
        find.text(
          'Counting jobs in any status · Company A · Main · set at Brightway '
          'Chimney',
        ),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('pm-scope-change')));
      await tester.pumpAndSettle();
      expect(
        find.text('Set for Company A · Main · this branch only'),
        findsOneWidget,
      );
    },
  );
}
