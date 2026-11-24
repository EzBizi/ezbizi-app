import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Gallery 210 (Jose 11-24, option 3): group titles 20 px on a row tinted
/// half in the group colour, a 4 px rule in it, the words in whichever of
/// white or ink reads; on a phone 18 px over at most two lines.
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tgh');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  const long = 'Unscheduled - Ready To Schedule - Columbus / Cincinnati';

  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  ApiClient server() => ApiClient(
    client: MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');
      if (path.endsWith('/me')) {
        return json({
          'data': {
            'id': 'u-1',
            'name': 'Jose',
            'email': 'jose@example.test',
            'permissions': ['tasks.view', 'tasks.edit'],
            'modules': <dynamic>[],
            'preferences': {
              'help.tours_seen': {'tasks': 99},
            },
            'organization': {
              'id': 'org-1',
              'name': 'Northside',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'companies': <dynamic>[],
            'franchises': <dynamic>[],
          },
        });
      }
      if (path == '/task-boards') {
        return json({
          'data': [
            {
              'id': 'b-1',
              'kind': 'franchise',
              'name': 'Ohio PM',
              'open_for_me': 0,
              'can_edit': true,
            },
          ],
        });
      }
      if (path == '/task-boards/b-1') {
        return json({
          'data': {
            'id': 'b-1',
            'kind': 'franchise',
            'name': 'Ohio PM',
            'can_edit': true,
            'assignable': <dynamic>[],
            'statuses': [
              {
                'id': 's-new',
                'name': 'Not started',
                'colour': '#8E9BA3',
                'is_done': false,
                'position': 0,
              },
            ],
            'columns': <dynamic>[],
            'groups': [
              {
                'id': 'g-y',
                'name': 'Yellow',
                'colour': '#ffcb00',
                'position': 0,
              },
              {'id': 'g-g', 'name': long, 'colour': '#037f4c', 'position': 1},
            ],
            'tasks': <dynamic>[],
          },
        });
      }
      return json({'data': <dynamic>[]});
    }),
  );

  Future<void> pump(
    WidgetTester tester,
    double width, {
    bool dark = false,
  }) async {
    tester.view.physicalSize = Size(width, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = server();
    final session = Session(api);
    await session.adopt('tok');
    await tester.pumpWidget(
      MaterialApp(
        theme: dark ? EzBiziTheme.dark() : EzBiziTheme.light(),
        home: Scaffold(
          body: TaskBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  double contrast(Color a, Color b) {
    final x = a.computeLuminance(), y = b.computeLuminance();
    return ((x > y ? x : y) + 0.05) / ((x > y ? y : x) + 0.05);
  }

  // The rule picks by contrast: on light, half green over F6F8FA is light,
  // so ink (8:1) beats white (2.2:1); on dark the same green takes white.
  // Dark yellow: white 4.4:1 beats ink 4.0:1 (told UI/UX 11-24).
  for (final (dark, greenWhite, yellowWhite, floor) in [
    (false, false, false, 4.5),
    (true, true, true, 4.3),
  ]) {
    testWidgets('desktop (${dark ? 'dark' : 'light'}): 20 px titles, readable '
        'ink, a 4 px rule in the group colour', (tester) async {
      await pump(tester, 1700, dark: dark);
      for (final (id, colour, white) in [
        ('g-y', const Color(0xFFFFCB00), yellowWhite),
        ('g-g', const Color(0xFF037F4C), greenWhite),
      ]) {
        final title = tester.widget<Text>(
          find.byKey(ValueKey('task-group-title-$id')),
        );
        final box = tester.widget<DecoratedBox>(
          find.byKey(ValueKey('task-group-head-$id')),
        );
        final deco = box.decoration as BoxDecoration;
        expect(title.style!.fontSize, 20);
        expect(contrast(title.style!.color!, deco.color!), greaterThan(floor));
        expect(title.style!.color == Colors.white, white, reason: id);
        final rule = (deco.border! as Border).bottom;
        expect([rule.width, rule.color], [4, colour]);
      }
    });
  }

  testWidgets('phone: a long name wraps to two lines', (tester) async {
    await pump(tester, 375);
    final title = tester.widget<Text>(
      find.byKey(const ValueKey('task-group-title-g-g')),
    );
    expect(title.maxLines, 2);
    expect(title.style!.fontSize, 18);
  });
}
