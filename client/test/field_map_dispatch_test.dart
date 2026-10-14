import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/field/map_overlays.dart';
import 'package:ezbizi_client/screens/field/map_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:latlong2/latlong.dart';

/// Field › Map for a dispatcher (Jose, 2026-09-30): a caller asks for an
/// ETA, the dispatcher finds the job, sees who is nearest, picks one, and
/// reads out the sentence.
void main() {
  testWidgets('find a job, see who is nearest, pick one, read the ETA', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pump(tester, onEta: asked.add);

    await tester.tap(find.text('Jobs 2'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'smith');
    await tester.pumpAndSettle();
    expect(find.text('Dana Smith'), findsOneWidget);
    expect(
      find.text('Lee Park'),
      findsNothing,
      reason: 'search filters the list',
    );

    await tester.tap(find.text('Dana Smith'));
    await tester.pumpAndSettle();
    expect(asked.last, {'job_id': 'j-1'});
    expect(find.text('Nearest now'), findsOneWidget);
    expect(find.text('Andre France · assigned'), findsOneWidget);
    expect(find.text('12 min · 4.1 mi'), findsOneWidget);

    await tester.tap(find.text('Andre France · assigned'));
    await tester.pumpAndSettle();
    expect(asked.last, {'job_id': 'j-1', 'user_id': 'u-2'});
    expect(find.text('12 min · 4.1 mi · arrives 2:42 PM'), findsOneWidget);
    expect(
      find.text(
        'Andre is about 12 minutes away and should arrive around 2:42 PM.',
      ),
      findsOneWidget,
    );
    expect(find.text('Copy'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('a technician opens the bar with what the phone reports', (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.text('Andre France').last);
    await tester.pumpAndSettle();

    expect(find.text('41% charging'), findsOneWidget);
    expect(find.text('27 avg · 58 top'), findsOneWidget);
    expect(find.text('Samsung SM-S918U · Android 15'), findsOneWidget);
    expect(find.text('v0.0.0330'), findsOneWidget);
    expect(find.textContaining('Next Dana Smith'), findsOneWidget);
  });

  testWidgets('on a phone the bar and the job card fit', (tester) async {
    await _pump(tester, size: const Size(390, 800));

    // On a phone the list starts folded to a chip.
    await tester.tap(find.text('Tracked · 1'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Andre France').first);
    await tester.pumpAndSettle();
    expect(find.text('41% charging'), findsOneWidget);

    await tester.tap(find.text('Jobs 2'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Dana Smith').first);
    await tester.pumpAndSettle();
    expect(find.text('Nearest now'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the legend names every colour on a wide screen', (tester) async {
    await _pump(tester);

    for (final label in [
      'Reporting',
      'At a job',
      'Stopped too long',
      'Not reporting',
      'Job with a technician',
      'Job not assigned',
      'Job you opened',
      'Route today',
      'Drive to the job',
    ]) {
      expect(find.text(label), findsOneWidget, reason: label);
    }
  });

  testWidgets('on a phone the legend opens from a button', (tester) async {
    await _pump(tester, size: const Size(390, 800));

    expect(find.text('Job not assigned'), findsNothing);
    await tester.tap(find.text('Legend'));
    await tester.pumpAndSettle();
    expect(find.text('Job not assigned'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the day arrows move the whole map to another day', (
    tester,
  ) async {
    final dates = <String?>[];
    await _pump(tester, onJobs: dates.add);
    final today = DateTime.now();
    String ymd(DateTime d) =>
        '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

    expect(dates.last, ymd(today));
    expect(find.textContaining('Today · '), findsOneWidget);

    await tester.tap(find.byTooltip('Previous day'));
    await tester.pumpAndSettle();
    expect(dates.last, ymd(today.subtract(const Duration(days: 1))));
    expect(find.text('Today'), findsOneWidget, reason: 'a way back to today');

    await tester.tap(find.text('Today'));
    await tester.pumpAndSettle();
    expect(dates.last, ymd(today));
  });

  testWidgets('people can be searched by name', (tester) async {
    await _pump(tester);

    await tester.enterText(find.byType(TextField), 'zzz');
    await tester.pumpAndSettle();
    expect(find.text('Nobody matches.'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'andre');
    await tester.pumpAndSettle();
    expect(find.text('Andre France'), findsWidgets);
    expect(find.text('Nobody matches.'), findsNothing);
  });

  testWidgets('headquarters are on the map with their name', (tester) async {
    await _pump(tester);

    expect(find.byType(FieldPlacePin), findsOneWidget);
    expect(find.text('A-1 Chimney'), findsOneWidget);
  });

  testWidgets('without the Jobs grant there is no Jobs list', (tester) async {
    await _pump(tester, canSeeJobs: false);

    expect(find.text('Jobs 2'), findsNothing);
    expect(find.text('Tracked'), findsOneWidget);
  });

  test("Google's encoded polyline decodes to the road", () {
    // Google's own worked example.
    expect(decodePolyline('_p~iF~ps|U_ulLnnqC_mqNvxq`@'), const [
      LatLng(38.5, -120.2),
      LatLng(40.7, -120.95),
      LatLng(43.252, -126.453),
    ]);
  });

  // The test above runs on the VM, where `~` is 64-bit signed and correct. On
  // the web `~` is unsigned 32-bit and turned every western longitude into
  // nonsense. No gate here runs a browser, so pin the source instead.
  test('the polyline decoder uses no ~ (wrong on the web)', () {
    final source = File(
      'lib/screens/field/map_overlays.dart',
    ).readAsStringSync();
    final start = source.indexOf('List<LatLng> decodePolyline(');
    final body = source
        .substring(start, source.indexOf('\n}', start))
        .split('\n')
        .map((line) => line.split('//').first)
        .join('\n');
    expect(body, isNot(contains('~')));
  });
}

Future<void> _pump(
  WidgetTester tester, {
  bool canSeeJobs = true,
  void Function(Map<String, String>)? onEta,
  void Function(String?)? onJobs,
  Size size = const Size(1500, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final now = DateTime.now().toUtc().toIso8601String();
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      final q = request.url.queryParameters;
      if (path.endsWith('/field/map/tiles')) {
        return _json({
          'data': {'url': null},
        });
      }
      if (path.endsWith('/field/map') && q.containsKey('user_id')) {
        return _json({
          'data': {'user': {}, 'points': []},
        });
      }
      if (path.endsWith('/field/map')) {
        return _json({
          'data': [
            {
              'user': {'id': 'u-2', 'name': 'Andre France'},
              'lat': 41.5,
              'lng': -81.6,
              'accuracy_m': 8,
              'last_seen_at': now,
              'fresh': true,
              'stopped': false,
              'silent': false,
              'at_job': null,
              'since': now,
              'distance_mi': 21.1,
              'speed_mph': 34,
              'avg_speed_mph': 27,
              'top_speed_mph': 58,
              'clocked_in_at': now,
              'next_job': {
                'id': 'j-1',
                'number': '1001',
                'customer': 'Dana Smith',
                'scheduled_for': '2026-09-30T15:00:00-04:00',
              },
              'device': {
                'battery_pct': 41,
                'charging': true,
                'platform': 'android',
                'os_version': 'Android 15',
                'model': 'Samsung SM-S918U',
                'app_version': '0.0.0330',
              },
            },
          ],
        });
      }
      if (path.endsWith('/field/places')) {
        return _json({
          'data': [
            {
              'company_id': 'c-1',
              'name': 'A-1 Chimney',
              'address': '100 Main St, Cleveland, OH 44114',
              'lat': 41.49,
              'lng': -81.69,
              'has_logo': false,
            },
          ],
        });
      }
      if (path.startsWith('/api/v1/branding/')) {
        return http.Response('', 404);
      }
      if (path.endsWith('/field/jobs')) {
        onJobs?.call(q['date']);
        return _json({
          'data': [
            _job('j-1', '1001', 'Dana Smith', 41.52, -81.58, tech: 'u-2'),
            _job('j-2', '1002', 'Lee Park', 41.40, -81.70),
          ],
        });
      }
      if (path.endsWith('/field/eta')) {
        onEta?.call(q);
        final base = {
          'job': _job('j-1', '1001', 'Dana Smith', 41.52, -81.58),
          'placed': true,
          'lat': 41.52,
          'lng': -81.58,
        };
        if (q.containsKey('user_id')) {
          return _json({
            'data': {
              ...base,
              'user': {'id': 'u-2', 'name': 'Andre France'},
              'from': {'lat': 41.5, 'lng': -81.6},
              'minutes': 12,
              'miles': 4.1,
              'arrive_at': '2:42 PM',
              'polyline': '_p~iF~ps|U_ulLnnqC',
              'say':
                  'Andre is about 12 minutes away and should arrive around 2:42 PM.',
            },
          });
        }
        return _json({
          'data': {
            ...base,
            'candidates': [
              {
                'user': {'id': 'u-2', 'name': 'Andre France'},
                'assigned': true,
                'minutes': 12,
                'miles': 4.1,
              },
            ],
          },
        });
      }
      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FieldMapTab(api: api, canSeeJobs: canSeeJobs),
      ),
    ),
  );
  await tester.pumpAndSettle();
  addTearDown(() => tester.pumpWidget(const SizedBox()));
}

Map<String, Object?> _job(
  String id,
  String number,
  String customer,
  double lat,
  double lng, {
  String? tech,
}) => {
  'job_id': id,
  'number': number,
  'title': 'Chimney sweep',
  'status': 'scheduled',
  'customer': customer,
  'address': '1 Main St, Mentor, OH 44060',
  'scheduled_for': '2026-09-30T15:00:00-04:00',
  'duration_minutes': 60,
  'lat': lat,
  'lng': lng,
  'tech': tech == null ? null : {'id': tech, 'name': 'Andre France'},
};

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );
