import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/business_profile/whatsapp_bulk_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// WhatsApp links in bulk (UI queue 163, design option 1 + phone 4).
void main() {
  const link = 'https://wa.me/15550001111';
  const rows = [
    {
      'id': 'm1',
      'name': 'Alpha',
      'branch': 'North',
      'current': null,
      'state': 'missing',
    },
    {
      'id': 'm2',
      'name': 'Beta',
      'branch': null,
      'current': null,
      'state': 'missing',
    },
    {
      'id': 'd1',
      'name': 'Gamma',
      'branch': 'East',
      'current': 'https://wa.me/1999',
      'state': 'different',
    },
    {
      'id': 's1',
      'name': 'Delta',
      'branch': 'West',
      'current': link,
      'state': 'same',
    },
    {
      'id': 'u1',
      'name': 'Echo',
      'branch': 'South',
      'current': null,
      'state': 'unreachable',
    },
  ];

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json'},
  );

  /// @return every apply body posted, in order.
  Future<List<Map<String, dynamic>>> pump(
    WidgetTester tester, {
    Size size = const Size(1200, 900),
    String? proposed = link,
    http.Response Function(List<dynamic> targets)? apply,
    List<Map<String, String>> numbers = const [],
    String? only,
  }) async {
    final posted = <Map<String, dynamic>>[];
    final client = MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');
      if (path == '/business-profile/whatsapp-links') {
        return json({'proposed': proposed, 'numbers': numbers, 'data': rows});
      }
      if (path == '/business-profile/whatsapp-links/apply') {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        posted.add(body);
        final targets = body['targets'] as List;
        return apply?.call(targets) ??
            json({
              'data': [
                for (final t in targets) {'id': t, 'name': t, 'ok': true},
              ],
            });
      }
      return json({}, 404);
    });

    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: WhatsAppBulkView(
            api: ApiClient(client: client),
            only: only,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return posted;
  }

  bool? ticked(WidgetTester tester, String id) =>
      tester.widget<Checkbox>(find.byKey(ValueKey('wa-bulk-tick-$id'))).value;

  Future<void> tap(WidgetTester tester, Finder f) async {
    await tester.ensureVisible(f);
    await tester.pumpAndSettle();
    await tester.tap(f);
    await tester.pumpAndSettle();
  }

  testWidgets('a state chip selects exactly its rows', (tester) async {
    await pump(tester);
    expect(find.text('Missing · 2'), findsOneWidget);
    await tap(tester, find.byKey(const ValueKey('wa-bulk-chip-missing')));
    expect(ticked(tester, 'm1'), isTrue);
    expect(ticked(tester, 'm2'), isTrue);
    expect(ticked(tester, 'd1'), isFalse);

    await tap(tester, find.byKey(const ValueKey('wa-bulk-chip-different')));
    expect(ticked(tester, 'm1'), isFalse);
    expect(ticked(tester, 'd1'), isTrue);
    expect(find.text('Review 1 listings'), findsOneWidget);
  });

  testWidgets('different is unticked, and picking it warns of the replace', (
    tester,
  ) async {
    await pump(tester);
    expect(ticked(tester, 'd1'), isFalse);
    expect(find.text('Different · Replace'), findsOneWidget);

    await tap(tester, find.byKey(const ValueKey('wa-bulk-tick-d1')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-tick-m1')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-review')));

    expect(find.byKey(const ValueKey('wa-bulk-preview')), findsOneWidget);
    expect(
      find.text(
        '1 listing already has another WhatsApp link; it will be replaced.',
      ),
      findsOneWidget,
    );
    expect(find.text('https://wa.me/1999 → $link'), findsOneWidget);
    expect(find.text('Set on 2 listings'), findsOneWidget);
    expect(
      find.text('Google can take a few minutes to show the button.'),
      findsOneWidget,
    );
  });

  testWidgets('unreachable and same rows cannot be ticked', (tester) async {
    await pump(tester);
    for (final id in ['u1', 's1']) {
      final box = tester.widget<Checkbox>(
        find.byKey(ValueKey('wa-bulk-tick-$id')),
      );
      expect(box.onChanged, isNull, reason: id);
    }
    expect(
      find.byWidgetPredicate(
        (w) => w is Tooltip && w.message == "Google account isn't connected",
      ),
      findsOneWidget,
    );
    expect(find.text('no change'), findsOneWidget);
  });

  testWidgets('apply shows each result, and Retry posts only the failed', (
    tester,
  ) async {
    var first = true;
    final posted = await pump(
      tester,
      apply: (targets) {
        if (!first) {
          return json({
            'data': [
              for (final t in targets) {'id': t, 'name': t, 'ok': true},
            ],
          });
        }
        first = false;
        return json({
          'data': [
            {'id': 'm1', 'name': 'Alpha', 'ok': true},
            {
              'id': 'm2',
              'name': 'Beta',
              'ok': false,
              'message': 'Google said no',
            },
          ],
        });
      },
    );

    await tap(tester, find.byKey(const ValueKey('wa-bulk-chip-missing')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-review')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-apply')));

    expect(posted.single['targets'], ['m1', 'm2']);
    expect(find.text('✓ Set'), findsOneWidget);
    expect(find.text('✗ Google said no'), findsOneWidget);

    await tap(tester, find.byKey(const ValueKey('wa-bulk-retry')));
    expect(posted.last['targets'], ['m2']);
    expect(find.text('✓ Set'), findsNWidgets(2));
    expect(find.byKey(const ValueKey('wa-bulk-retry')), findsNothing);
  });

  testWidgets('a 409 message is shown as the server wrote it', (tester) async {
    await pump(
      tester,
      apply: (_) => json({'message': 'Another update is running.'}, 409),
    );
    await tap(tester, find.byKey(const ValueKey('wa-bulk-tick-m1')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-review')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-apply')));
    expect(find.text('Another update is running.'), findsOneWidget);
  });

  testWidgets('no WhatsApp number: told so, nothing can be ticked', (
    tester,
  ) async {
    await pump(tester, proposed: null);
    expect(find.text('Connect a WhatsApp number first.'), findsOneWidget);
    final box = tester.widget<Checkbox>(
      find.byKey(const ValueKey('wa-bulk-tick-m1')),
    );
    expect(box.onChanged, isNull);
  });

  testWidgets('a phone draws cards and a Review button at the bottom', (
    tester,
  ) async {
    await pump(tester, size: const Size(390, 844));
    expect(find.text('Alpha · North'), findsOneWidget);
    await tap(tester, find.byKey(const ValueKey('wa-bulk-chip-missing')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-review')));
    expect(find.text('Set on 2 listings'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('a second number can be chosen and its link is applied', (
    tester,
  ) async {
    final posted = await pump(
      tester,
      numbers: const [
        {'number': '+15550001111', 'link': link},
        {'number': '+15550002222', 'link': 'https://wa.me/15550002222'},
      ],
    );
    await tap(
      tester,
      find.byKey(const ValueKey('wa-bulk-number-+15550002222')),
    );
    // Delta held the first link, so it now differs and can be ticked.
    await tap(tester, find.byKey(const ValueKey('wa-bulk-tick-s1')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-review')));
    await tap(tester, find.byKey(const ValueKey('wa-bulk-apply')));
    expect(posted.single['targets'], ['s1']);
    expect(posted.single['link'], 'https://wa.me/15550002222');
  });

  testWidgets('one listing: only it is shown and it starts ticked', (
    tester,
  ) async {
    await pump(tester, only: 'd1');
    expect(find.byKey(const ValueKey('wa-bulk-row-d1')), findsOneWidget);
    expect(find.byKey(const ValueKey('wa-bulk-row-m1')), findsNothing);
    expect(ticked(tester, 'd1'), isTrue);
    expect(find.byType(ChoiceChip), findsNothing);
  });
}
