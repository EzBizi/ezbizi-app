import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers/memberships_promotions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Customers › Memberships › Promotions, round two (Jose, 2026-11-09):
/// presets, filters with a live count, per-row untick, the phone render of
/// the first customer, and a test send that is not the campaign.
void main() {
  testWidgets(
    'filters and unticks narrow what is sent; the phones show the first customer',
    (tester) async {
      final server = _Server();
      await _pump(tester, server);

      await tester.tap(find.text('Last 12 months'));
      await tester.pumpAndSettle();
      expect(server.previews, 1);
      expect(_count(tester), startsWith('3 customers'));
      expect(find.text('Sends from +18559553322'), findsOneWidget);

      // Filters run on the screen: job type, then leave out open jobs.
      await tester.tap(find.widgetWithText(FilterChip, 'Chimney Sweep'));
      await tester.pumpAndSettle();
      expect(_count(tester), startsWith('2 customers'));
      await tester.ensureVisible(
        find.byKey(const ValueKey('promotions-exclude-open')),
      );
      await tester.tap(find.byKey(const ValueKey('promotions-exclude-open')));
      await tester.pumpAndSettle();
      expect(_count(tester), startsWith('1 customers'));
      await tester.tap(find.byKey(const ValueKey('promotions-exclude-open')));
      await tester.pumpAndSettle();

      // Untick Ann: Bo is first, and both phones render him.
      await tester.ensureVisible(
        find.byKey(const ValueKey('promotions-row-+12035550011')),
      );
      await tester.tap(
        find.byKey(const ValueKey('promotions-row-+12035550011')),
      );
      await tester.pumpAndSettle();
      expect(_count(tester), startsWith('1 customers'));
      final rendered = 'Hi Bo, it\'s A-1 Chimney.\n\nReply STOP to opt out.';
      for (final phone in ['promotions-android', 'promotions-ios']) {
        expect(
          find.descendant(
            of: find.byKey(ValueKey(phone)),
            matching: find.text(rendered),
          ),
          findsOneWidget,
        );
      }
      expect(find.textContaining('· 1 text'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('promotions-picture')),
        findsOneWidget,
        reason: 'the line takes MMS',
      );

      // A test goes to the typed number with Bo's fill-ins; nothing is queued.
      await tester.ensureVisible(
        find.byKey(const ValueKey('promotions-test-to')),
      );
      await tester.enterText(
        find.byKey(const ValueKey('promotions-test-to')),
        '2035550099',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('promotions-test-send')));
      await tester.pumpAndSettle();
      expect(server.posts.map((p) => p.$1), [
        '/api/v1/memberships/promotions/test',
      ]);
      expect(server.posts.single.$2, containsPair('first_name', 'Bo'));
      expect(server.posts.single.$2, containsPair('to', '2035550099'));

      // Send takes two presses and carries only the ticked phone.
      await tester.ensureVisible(find.byKey(const ValueKey('promotions-send')));
      await tester.tap(find.byKey(const ValueKey('promotions-send')));
      await tester.pumpAndSettle();
      expect(server.posts, hasLength(1));
      await tester.tap(find.byKey(const ValueKey('promotions-send')));
      await tester.pumpAndSettle();
      expect(server.posts.last.$1, '/api/v1/memberships/promotions');
      expect(server.posts.last.$2['phones'], ['+12035550012']);
    },
  );

  testWidgets('a line without MMS says so instead of offering a picture', (
    tester,
  ) async {
    final server = _Server()..mms = false;
    await _pump(tester, server);
    await tester.tap(find.text('Last month'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('promotions-picture')), findsNothing);
    expect(
      find.text(
        '+18559553322 cannot send pictures, so the text goes without one.',
      ),
      findsOneWidget,
    );
  });
}

String _count(WidgetTester tester) =>
    tester.widget<Text>(find.byKey(const ValueKey('promotions-count'))).data!;

Map<String, dynamic> _row(
  String name,
  String first,
  String phone,
  String type, {
  bool open = false,
}) => {
  'phone': phone,
  'name': name,
  'first_name': first,
  'franchise_id': 'f-1',
  'franchise': 'A-1 Chimney CT',
  'company': 'A-1 Chimney',
  'job_date': '2025-08-01',
  'customer_tags': <String>[],
  'last_job': '2025-08-01',
  'open_job': open,
  'member': false,
  'jobs': [
    {
      'date': '2025-08-01',
      'type': type,
      'tags': <String>[],
      'services': <String>[],
      'amount': 299,
    },
  ],
};

class _Server {
  bool mms = true;
  int previews = 0;
  final posts = <(String, Map<String, dynamic>)>[];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/me')) return _json({'data': _me});
    if (request.method == 'POST') {
      posts.add((path, jsonDecode(request.body) as Map<String, dynamic>));
      return _json({
        'data': {'to': '+12035550099', 'recipients': 1},
      }, path.endsWith('/test') ? 200 : 202);
    }
    if (path.endsWith('/preview')) {
      previews++;
      return _json({
        'data': {
          'recipients': 3,
          'opted_out': 0,
          'rows': [
            _row('Ann Ash', 'Ann', '+12035550011', 'Chimney Sweep', open: true),
            _row('Bo Birch', 'Bo', '+12035550012', 'Chimney Sweep'),
            _row('Cy Cole', 'Cy', '+12035550013', 'Chimney Inspection'),
          ],
          'services': <String, String>{},
          'senders': [
            {'e164': '+18559553322', 'label': null, 'mms': mms},
          ],
          'message': "Hi {first_name}, it's {company}.",
        },
      });
    }
    return _json({'data': <Object>[]});
  }
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

final _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': [
    'memberships.view',
    'memberships.edit',
    'memberships.promotions.view',
  ],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Harbor Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Harbor Group',
    'path': ['Harbor Group'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};

Future<void> _pump(WidgetTester tester, _Server server) async {
  tester.view.physicalSize = const Size(1400, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = ApiClient(client: MockClient(server.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: MembershipsPromotions(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
