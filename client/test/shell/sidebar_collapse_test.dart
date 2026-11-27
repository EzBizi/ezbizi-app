import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

// Jose 11-16 (0.0.0575): collapsing the sidebar turned the column into a flat
// grey block until he clicked something. A release build paints a widget that
// threw while building or laying out as a grey ErrorWidget, so every frame of
// the collapse and of the expand must be exception-free, and the end state
// must show every icon with nothing modal or faded over it.
const _names = [
  'Dashboard',
  'Human Resources',
  'Customers',
  'Jobs',
  'Scheduling',
  'Inspection',
  'Marketing',
  'Web Management',
  'Phone',
  'Fleet Management',
  'Inventory',
  'Services',
  'Email',
  'Automations',
  'Monitoring',
];

Widget _app(bool expanded, double height) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(
    body: Row(
      children: [
        NavigationSidebar(
          expanded: expanded,
          width: expanded ? 240 : NavigationSidebar.collapsedWidth,
          destinations: [
            for (final n in _names)
              Destination(n, Icons.circle_outlined, const SizedBox()),
          ],
          pinned: [
            Destination('Settings', Icons.settings_outlined, const SizedBox()),
          ],
          staffOnly: const [],
          selectedIndex: null,
          pinnedIndex: 0,
          staffIndex: null,
          onSelected: (_) {},
          onPinnedSelected: (_) {},
          onStaffSelected: (_) {},
          onResized: (_) {},
          onArranged: (_) {},
          headingAt: const {0: 'Work', 5: 'Customers'},
          divideAfter: const {9},
        ),
        const Expanded(child: SizedBox()),
      ],
    ),
  ),
);

final _list = find
    .descendant(of: find.byType(Scrollbar), matching: find.byType(Scrollable))
    .first;

void main() {
  for (final height in const [1000.0, 500.0]) {
    testWidgets('collapse and expand draw every frame (h=$height)', (
      tester,
    ) async {
      tester.view.physicalSize = Size(1400, height);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(_app(true, height));
      await tester.pumpAndSettle();
      // The rail's scroll view must survive the toggle. Remounting it is what
      // left the controller holding two positions and threw in _MoreBelow.
      final scrollState = tester.state(_list);

      for (final expanded in const [false, true, false]) {
        await tester.pumpWidget(_app(expanded, height));
        for (var f = 0; f < 30; f++) {
          await tester.pump(const Duration(milliseconds: 16));
          expect(
            tester.takeException(),
            isNull,
            reason: 'frame $f of expanded=$expanded threw',
          );
          expect(
            find.byType(ErrorWidget),
            findsNothing,
            reason: 'frame $f of expanded=$expanded drew an ErrorWidget',
          );
        }
        await tester.pumpAndSettle();
        expect(
          tester.state(_list),
          same(scrollState),
          reason: 'expanded=$expanded remounted the sidebar list',
        );

        final sidebar = find.byType(NavigationSidebar);
        expect(
          find.descendant(of: sidebar, matching: find.byType(ModalBarrier)),
          findsNothing,
        );
        for (final o in tester.widgetList<Opacity>(
          find.descendant(of: sidebar, matching: find.byType(Opacity)),
        )) {
          // Rail labels are faded by design when collapsed; nothing else is.
          expect(o.opacity == 1 || !expanded, isTrue);
        }
        final icons = find.descendant(
          of: sidebar,
          matching: find.byIcon(Icons.circle_outlined),
        );
        expect(icons, findsNWidgets(_names.length));
        final railBox = tester.getRect(sidebar);
        // Each visible icon is painted inside the sidebar column.
        for (final e in icons.evaluate().take(4)) {
          final r = tester.getRect(find.byWidget(e.widget).first);
          expect(r.left >= railBox.left && r.right <= railBox.right, isTrue);
        }
      }
    });
  }
}
