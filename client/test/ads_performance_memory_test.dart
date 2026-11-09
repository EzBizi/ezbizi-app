import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ads_performance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Google Ads screen as somebody USES it: leaving and coming back, adding
/// a column, pausing a campaign.
///
/// Jose, 2026-09-24: *"every time i leave one of the ads pages and come back it
/// loads everything all over again ... it should load the cache and in the
/// meantime fetch the new stuff and then refresh itself (seamlessly)"* - and in
/// the same hour, pause and enable from EzBizi.
///
/// The memory is app-wide on purpose, so the first obligation here is that it
/// is keyed to who is looking and at which company: one company's spend must
/// never be drawn, even for a frame, under another's.
void main() {
  setUp(forgetAdsMemory);

  late List<http.Request> requests;

  /// Performance answers, in order. A Completer lets a test hold one back and
  /// look at the screen while it is still in flight.
  late List<FutureOr<http.Response>> performance;

  Map<String, dynamic> meBody({required List<String> permissions, String scopeId = 'co-1'}) => {
    'data': {
      'id': 'u-1',
      'name': 'Jose Silva',
      'email': 'jose@example.com',
      'has_avatar': false,
      'role': {'id': 'role-1', 'name': 'Administrator'},
      'permissions': permissions,
      'modules': [
        {'key': 'ads_performance', 'label': 'Google Ads', 'available': true, 'reachable': true},
      ],
      'preferences': <String, dynamic>{},
      'organization': {
        'id': 'org-1',
        'name': 'A1-Group',
        'franchise_label': 'Franchise',
        'is_read_only': false,
      },
      'scope': {'type': 'company', 'id': scopeId, 'label': 'A-1 Chimney', 'path': ['A1-Group', 'A-1 Chimney']},
      'companies': <Map<String, Object?>>[],
      'franchises': <Map<String, Object?>>[],
    },
  };

  http.Response json(Object body, [int status = 200]) =>
      http.Response(jsonEncode(body), status, headers: const {'content-type': 'application/json'});

  Map<String, dynamic> report({int clicks = 412}) => {
    'connection_id': 'c1',
    'currency': 'USD',
    'account': {
      'id': '4941850746',
      'name': 'A1 Chimney - Cleveland',
      'clicks': clicks,
      'impressions': 10500,
      'conversions': 31.5,
      'all_conversions': 44.25,
      'cost': 1234.56,
      'currency': 'USD',
      'ctr': 0.0392,
    },
    'series': <dynamic>[],
    'campaigns': [
      {
        'id': '111',
        'name': 'Chimney - Cleveland - Search',
        'status': 'ENABLED',
        'clicks': 300,
        'impressions': 8000,
        'conversions': 20,
        'all_conversions': 28,
        'cost': 900,
        'ctr': 0.0375,
      },
      {
        'id': '222',
        'name': 'Chimney - Cleveland - Display',
        'status': 'PAUSED',
        'clicks': 0,
        'impressions': 0,
        'conversions': 0,
        'all_conversions': 0,
        'cost': 0,
        // No impressions: no rate. Drawn as a dash, never as 0%.
        'ctr': null,
      },
    ],
  };

  ApiClient client({required List<String> permissions, String scopeId = 'co-1'}) => ApiClient(
    client: MockClient((request) async {
      requests.add(request);
      final path = request.url.path;

      if (path.endsWith('/me')) return json(meBody(permissions: permissions, scopeId: scopeId));
      if (path.endsWith('/ads/accounts')) {
        return json({
          'data': [
            {'connection_id': 'c1', 'customer_id': '4941850746', 'name': 'A1 Chimney - Cleveland'},
            // Two, so these tests still pick by hand: a lone account is
            // chosen on its own (UI review 2026-10-08).
            {'connection_id': 'c2', 'customer_id': '8839813550', 'name': 'A1 Chimney - Hamden'},
          ],
        });
      }
      if (path.endsWith('/ads/performance')) return performance.removeAt(0);
      if (path.endsWith('/ads/campaigns/status')) {
        final ids = (jsonDecode(request.body)['campaign_ids'] as List).cast<String>();
        return json({
          'data': [for (final id in ids) {'campaign_id': id, 'ok': true, 'because': null}],
        });
      }
      return json({'data': <dynamic>[]});
    }),
  );

  Future<void> open(WidgetTester tester, ApiClient api) async {
    tester.view.physicalSize = const Size(1600, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: AdsPerformanceScreen(api: api, session: session)),
      ),
    );
  }

  /// Navigating away: the screen's widget is gone, and with it its State.
  Future<void> leave(WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: Text('somewhere else'))));
    await tester.pumpAndSettle();
  }

  setUp(() {
    requests = [];
    performance = [];
  });

  /// THE WHOLE REQUEST: coming back draws the last figures at once, then the
  /// fresh ones replace them.
  ///
  /// The second answer is HELD while the screen is inspected, which is the
  /// only way to see the moment that matters - the frame between arriving and
  /// Google answering. Without the hold the fresh figures would already be
  /// there and the test could not tell remembering from reloading.
  testWidgets('coming back shows the last figures at once, then the new ones', (tester) async {
    performance.add(json({'data': [report(clicks: 412)], 'refused': <dynamic>[]}));
    final held = Completer<http.Response>();
    performance.add(held.future);

    final api = client(permissions: const ['ads_performance.view']);

    await open(tester, api);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();
    expect(find.text('412'), findsOneWidget);

    await leave(tester);

    await open(tester, api);
    await tester.pump();

    // Remembered: the figures are there before Google has answered.
    expect(find.text('412'), findsOneWidget, reason: 'the last figures, drawn at once');
    expect(find.byKey(const ValueKey('ads-refreshing')), findsOneWidget, reason: 'and a quiet bar says they are being re-read');
    expect(find.byKey(const ValueKey('ads-pick-one')), findsNothing, reason: 'the same account is still selected');

    held.complete(json({'data': [report(clicks: 999)], 'refused': <dynamic>[]}));
    await tester.pumpAndSettle();

    expect(find.text('999'), findsOneWidget, reason: 'the fresh figures replaced them');
    expect(find.text('412'), findsNothing);
    expect(find.byKey(const ValueKey('ads-refreshing')), findsNothing);
  });

  /// A different company in view is a different memory, and starts cold.
  ///
  /// The dangerous version of this feature is the one that draws Atlanta's
  /// spend for a frame under the company somebody just switched to.
  testWidgets('another company in view does not see what this one remembered', (tester) async {
    performance.add(json({'data': [report(clicks: 412)], 'refused': <dynamic>[]}));

    await open(tester, client(permissions: const ['ads_performance.view'], scopeId: 'co-1'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();
    expect(find.text('412'), findsOneWidget);

    await leave(tester);

    await open(tester, client(permissions: const ['ads_performance.view'], scopeId: 'co-2'));
    await tester.pump();

    expect(find.text('412'), findsNothing, reason: 'nothing of the other company, not even for a frame');
    expect(find.byKey(const ValueKey('ads-pick-one')), findsOneWidget);
  });

  /// A refresh that fails keeps the figures and says how old they are.
  testWidgets('a failed refresh keeps the last figures and says so', (tester) async {
    performance.add(json({'data': [report(clicks: 412)], 'refused': <dynamic>[]}));
    performance.add(json({'message': 'Google did not answer.'}, 502));

    final api = client(permissions: const ['ads_performance.view']);

    await open(tester, api);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();
    await leave(tester);

    await open(tester, api);
    await tester.pumpAndSettle();

    expect(find.text('412'), findsOneWidget, reason: 'the last true figures stay');
    expect(find.byKey(const ValueKey('ads-stale')), findsOneWidget);
    expect(find.textContaining('Showing the figures read at'), findsOneWidget);
  });

  /// OPTIONAL COLUMNS: "All other Metric columns must be made available, as an
  /// option" - and the required five are not in the menu to be turned off.
  testWidgets('an optional column can be added, and a required one cannot be removed', (tester) async {
    performance.add(json({'data': [report()], 'refused': <dynamic>[]}));

    await open(tester, client(permissions: const ['ads_performance.view']));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('ads-columns')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('ads-column-ctr')), findsOneWidget);
    expect(find.byKey(const ValueKey('ads-column-interactions')), findsOneWidget);
    for (final required in ['clicks', 'cost', 'impressions', 'conversions', 'all_conversions']) {
      expect(find.byKey(ValueKey('ads-column-$required')), findsNothing, reason: '$required is shown by default and cannot be switched off');
    }

    await tester.tap(find.byKey(const ValueKey('ads-column-ctr')));
    await tester.pumpAndSettle();

    expect(find.text('3.75%'), findsOneWidget, reason: 'the campaign\'s rate, as a percentage');
    expect(find.text('3.92%'), findsOneWidget, reason: 'and the account\'s, beside the five');
    expect(find.text('—'), findsWidgets, reason: 'a campaign with no impressions has no rate, not 0%');
  });

  /// PAUSE: tick, press, confirm - and Google is asked, then the page re-read.
  testWidgets('a manager pauses a ticked campaign after confirming', (tester) async {
    performance.add(json({'data': [report()], 'refused': <dynamic>[]}));
    performance.add(json({'data': [report()], 'refused': <dynamic>[]}));

    await open(tester, client(permissions: const ['ads_performance.view', 'ads_manager.manage']));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('ads-pick-111')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('ads-pause')));
    await tester.pumpAndSettle();

    // Nothing is sent until the consequence has been read and answered.
    expect(requests.where((r) => r.url.path.endsWith('/ads/campaigns/status')), isEmpty);
    expect(find.textContaining('Pause 1 campaign in A1 Chimney - Cleveland?'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('ads-confirm')));
    await tester.pumpAndSettle();

    final sent = requests.where((r) => r.url.path.endsWith('/ads/campaigns/status')).single;
    expect(jsonDecode(sent.body), {
      'connection_id': 'c1',
      'campaign_ids': ['111'],
      'status': 'PAUSED',
    });

    expect(find.text('Paused 1 of 1.'), findsOneWidget);
    expect(
      requests.where((r) => r.url.path.endsWith('/ads/performance')),
      hasLength(2),
      reason: 'the page is re-read from Google rather than patched locally',
    );
  });

  /// The new controls fit a phone: Columns in the header, the action bar and
  /// its confirmation. An overflow paints stripes and passes every finder, so
  /// it is caught where Flutter reports it.
  testWidgets('the columns button and the pause confirmation fit a phone', (tester) async {
    performance.add(json({'data': [report()], 'refused': <dynamic>[]}));

    final complaints = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (d) {
      complaints.add(d.exceptionAsString());
      previous?.call(d);
    };
    addTearDown(() => FlutterError.onError = previous);

    await open(tester, client(permissions: const ['ads_performance.view', 'ads_manager.manage']));
    tester.view.physicalSize = const Size(375, 1400);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const ValueKey('ads-pick-111')));
    await tester.tap(find.byKey(const ValueKey('ads-pick-111')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const ValueKey('ads-pause')));
    await tester.tap(find.byKey(const ValueKey('ads-pause')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('ads-confirm')), findsOneWidget, reason: 'the control: the confirmation is drawn');
    expect(complaints.where((c) => c.contains('overflowed')), isEmpty);
  });

  /// Reading spend does not offer switching it off.
  testWidgets('without the manage grant there is nothing to tick', (tester) async {
    performance.add(json({'data': [report()], 'refused': <dynamic>[]}));

    await open(tester, client(permissions: const ['ads_performance.view']));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ads-account-c1')));
    await tester.pumpAndSettle();

    // The control: the table IS drawn, so the absence is about the grant.
    expect(find.byKey(const ValueKey('ads-campaigns')), findsOneWidget);
    expect(find.byType(Checkbox), findsNothing);
    expect(find.byKey(const ValueKey('ads-pause')), findsNothing);
  });
}
