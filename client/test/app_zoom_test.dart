import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/widgets/app_zoom.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Desktop zoom (Kimberly 01a213cf, approved by Jose 2026-11-25): Ctrl + / -,
/// Ctrl 0, Ctrl and the wheel; the whole window scales and the level is the
/// person's preference.
void main() {
  late List<Object?> saved;

  Future<Session> pump(
    WidgetTester tester,
    Widget page, {
    int zoom = 100,
    bool enabled = true,
    Size size = const Size(1200, 800),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    saved = [];
    final api = ApiClient(
      client: MockClient((r) async {
        if (r.url.path.endsWith('/me/preferences')) {
          saved.add((jsonDecode(r.body) as Map)['preferences']);
          return http.Response('{"data": {}}', 200);
        }
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'u-1',
              'name': 'Kim',
              'email': 'kim@example.test',
              'permissions': <String>[],
              'modules': <Object>[],
              'preferences': {'appearance.zoom': zoom},
              'organization': {'id': 'o', 'name': 'Org'},
              'scope': {'type': 'organization', 'id': 'o', 'label': 'Org'},
            },
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        builder: (context, child) =>
            AppZoom(session: session, enabled: enabled, child: child!),
        home: Scaffold(body: page),
      ),
    );
    await tester.pump();
    return session;
  }

  testWidgets('150%: laid out at the window over 1.5, drawn back to fill it', (
    tester,
  ) async {
    late Size seen;
    await pump(
      tester,
      Builder(
        builder: (context) {
          seen = MediaQuery.sizeOf(context);
          return const SizedBox(key: Key('probe'), width: 100, height: 40);
        },
      ),
      zoom: 150,
    );
    expect(seen, const Size(800, 800 / 1.5));
    expect(tester.getRect(find.byKey(const Key('probe'))).width, 150);
  });

  testWidgets('Ctrl + steps up, Ctrl - down, Ctrl 0 resets; saved, with the '
      'toast', (tester) async {
    final session = await pump(tester, const SizedBox());
    Future<void> chord(LogicalKeyboardKey key) async {
      await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
      await tester.sendKeyEvent(key);
      await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
      await tester.pump();
    }

    await chord(LogicalKeyboardKey.equal);
    expect(session.me!.zoom, 1.1);
    expect(find.text('Zoom 110%'), findsOneWidget);
    await chord(LogicalKeyboardKey.equal);
    expect(session.me!.zoom, 1.25);
    await chord(LogicalKeyboardKey.minus);
    expect(session.me!.zoom, 1.1);
    await chord(LogicalKeyboardKey.digit0);
    expect(session.me!.zoom, 1.0);
    await tester.pump();
    expect(saved, [
      {'appearance.zoom': 110},
      {'appearance.zoom': 125},
      {'appearance.zoom': 110},
      {'appearance.zoom': 100},
    ]);
    // The toast's Reset, and it goes after two seconds.
    await chord(LogicalKeyboardKey.equal);
    await tester.tap(find.byKey(const ValueKey('app-zoom-reset')));
    await tester.pump();
    expect(session.me!.zoom, 1.0);
    await tester.pump(const Duration(seconds: 3));
    expect(find.byKey(const ValueKey('app-zoom-toast')), findsNothing);
  });

  testWidgets('Ctrl and the wheel zoom, and the list under it stays still', (
    tester,
  ) async {
    final controller = ScrollController();
    addTearDown(controller.dispose);
    final session = await pump(
      tester,
      ListView(
        controller: controller,
        children: [for (var i = 0; i < 100; i++) Text('Row $i')],
      ),
    );
    final at = tester.getCenter(find.byType(ListView));
    final pointer = TestPointer(1, PointerDeviceKind.mouse);
    await tester.sendEventToBinding(pointer.hover(at));
    // Without Ctrl the wheel scrolls as ever: half way down first, so a
    // scroll either way would show.
    await tester.sendEventToBinding(pointer.scroll(const Offset(0, 300)));
    await tester.pump();
    final before = controller.offset;
    expect(before, greaterThan(0));
    expect(session.me!.zoom, 1.0);
    // With Ctrl it zooms, and the list does not move.
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendEventToBinding(pointer.scroll(const Offset(0, -100)));
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    await tester.pump();
    expect(session.me!.zoom, 1.1);
    expect(controller.offset, before);
  });

  testWidgets('web and phones: nothing scales, keys do nothing', (
    tester,
  ) async {
    final session = await pump(tester, const SizedBox(), enabled: false);
    expect(find.byKey(const ValueKey('app-zoom')), findsNothing);
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.equal);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    await tester.pump();
    expect(session.me!.zoom, 1.0);
    expect(saved, isEmpty);
  });

  // The desk: the layouts must hold at 150%. A 1280x800 window at 150% is a
  // 853x533 page, at 200% 640x400: the shell's sidebar, its tail and a page.
  for (final zoom in [150, 200]) {
    testWidgets('the shell holds at $zoom% on a 1280x800 window', (
      tester,
    ) async {
      await pump(
        tester,
        Row(
          children: [
            NavigationSidebar(
              expanded: true,
              width: 220,
              destinations: [
                for (final n in const [
                  'Dashboard',
                  'Customers',
                  'Jobs',
                  'Scheduling',
                  'Task Board',
                  'Inventory',
                  'Fleet Management',
                  'Communication',
                ])
                  Destination(n, Icons.circle_outlined, const SizedBox()),
              ],
              pinned: [
                Destination(
                  'Settings',
                  Icons.settings_outlined,
                  const SizedBox(),
                ),
              ],
              staffOnly: const [],
              selectedIndex: 0,
              pinnedIndex: null,
              staffIndex: null,
              onSelected: (_) {},
              onPinnedSelected: (_) {},
              onStaffSelected: (_) {},
              onArranged: (_) {},
            ),
            const Expanded(child: Center(child: Text('Page'))),
          ],
        ),
        zoom: zoom,
        size: const Size(1280, 800),
      );
      expect(tester.takeException(), isNull);
      expect(find.text('Settings'), findsOneWidget);
    });
  }
}
