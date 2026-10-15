import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/availability_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The availability pane's refresh, made cheap (2026-10-15, by Monitoring
/// while Scheduling was unstaffed) without weakening Jose's 10-05 rule:
/// another dispatcher's booking still shows on the next tick.
void main() {
  group('availabilityShouldRefresh', () {
    final now = DateTime(2026, 10, 15, 12);
    bool ask({bool inFront = true, int idleMin = 0, int sinceRefreshMin = 0}) =>
        availabilityShouldRefresh(
          inFront: inFront,
          lastInput: now.subtract(Duration(minutes: idleMin)),
          lastRefresh: now.subtract(Duration(minutes: sinceRefreshMin)),
          now: now,
        );

    test('in use and in front: every tick', () => expect(ask(), isTrue));
    test('window not in front: never', () => expect(ask(inFront: false), isFalse));
    test('idle 10 min, refreshed 1 min ago: wait', () => expect(ask(idleMin: 10, sinceRefreshMin: 1), isFalse));
    test('idle 10 min, refreshed 5 min ago: ask', () => expect(ask(idleMin: 10, sinceRefreshMin: 5), isTrue));
  });

  testWidgets('an unchanged answer redraws nothing and skips the suggestion; a change still arrives',
      (tester) async {
    tester.view.physicalSize = const Size(1400, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var version = 1;
    var availabilityCalls = 0;
    var notModified = 0;
    var suggestionCalls = 0;

    String body() => jsonEncode({'data': {'areas': [
          {'area_id': 'a1', 'area_name': 'Dallas v$version', 'franchise_name': 'F', 'configured': true,
            'first': {'date': '2026-10-16', 'windows': <dynamic>[], 'no_time': 1}, 'after': <dynamic>[], 'closed': <dynamic>[]},
        ]}});

    http.Response json(String b, [Map<String, String> h = const {}]) =>
        http.Response(b, 200, headers: {'content-type': 'application/json', ...h});

    final api = ApiClient(client: MockClient((r) async {
      final p = r.url.path;
      if (p.endsWith('/me')) {
        return json(jsonEncode({'data': {
          'id': 'u', 'name': 'L', 'email': 'l@e.test', 'permissions': <String>[], 'modules': <dynamic>[],
          'preferences': <String, dynamic>{},
          'organization': {'id': 'o', 'name': 'A', 'franchise_label': 'Franchise', 'is_read_only': false},
          'companies': <dynamic>[], 'franchises': <dynamic>[],
        }}));
      }
      if (p.endsWith('/scheduling/availability/suggestion')) {
        suggestionCalls++;
        return json('{"data":null}');
      }
      if (p.endsWith('/scheduling/availability')) {
        availabilityCalls++;
        final etag = '"v$version"';
        if (r.headers['If-None-Match'] == etag) {
          notModified++;
          return http.Response('', 304, headers: {'etag': etag});
        }
        return json(body(), {'etag': etag});
      }
      return json('{"data":[]}');
    }));
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: AvailabilityPane(api: api, session: session, visible: true, initialZip: '75201')),
    ));
    await tester.pumpAndSettle();
    expect(find.textContaining('DALLAS V1'), findsOneWidget);
    expect(suggestionCalls, 1);

    // Nothing changed: the refresh is a 304, and the suggestion is not asked.
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(availabilityCalls, 2);
    expect(notModified, 1);
    expect(suggestionCalls, 1, reason: 'An unchanged answer asks nothing more.');

    // Another dispatcher books: the next tick shows it (Jose's 10-05 rule).
    version = 2;
    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(find.textContaining('DALLAS V2'), findsOneWidget);
    expect(suggestionCalls, 2, reason: 'A changed answer re-asks the suggestion.');

    await tester.pumpWidget(const SizedBox()); // dispose: stop the timer
  });
}
