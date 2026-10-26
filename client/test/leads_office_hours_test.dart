import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_office_hours.dart';
import 'package:ezbizi_client/widgets/open_destination.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Leads › Manage › Office hours: UI queue 138, Jose's 1 + 4 (2026-10-22:
/// "better, go ahead, and places that do not have time set up should be
/// highlighted"). Every name here is invented.
final _puts = <({String path, Map<String, dynamic> body})>[];
final _opened = <String>[];

Map<String, dynamic> _day(String open, String close) => {
  'open': open,
  'close': close,
};

Map<String, dynamic> _week(
  Map<String, dynamic>? weekday, {
  Map<String, dynamic>? saturday,
  Map<String, dynamic>? sunday,
}) => {
  'monday': weekday,
  'tuesday': weekday,
  'wednesday': weekday,
  'thursday': weekday,
  'friday': weekday,
  'saturday': saturday,
  'sunday': sunday,
};

final _default = _week(_day('08:00', '17:00'));

List<Map<String, dynamic>> _places() => [
  {
    'scope': 'organization',
    'id': 'org-1',
    'parent_id': null,
    'name': 'Lakeview Services',
    'hours': _default,
    'hours_own': false,
    'hours_from': null,
    'timezone': 'America/New_York',
    'timezone_own': false,
    'timezone_from': null,
  },
  {
    'scope': 'company',
    'id': 'co-1',
    'parent_id': 'org-1',
    'name': 'Harbor Home',
    'hours': _week(_day('07:00', '19:00'), saturday: _day('09:00', '13:00')),
    'hours_own': true,
    'hours_from': 'Harbor Home',
    'timezone': 'America/New_York',
    'timezone_own': false,
    'timezone_from': null,
  },
  {
    'scope': 'franchise',
    'id': 'fr-1',
    'parent_id': 'co-1',
    'name': 'Springfield',
    'hours': _week(_day('07:00', '19:00'), saturday: _day('09:00', '13:00')),
    'hours_own': false,
    'hours_from': 'Harbor Home',
    'timezone': 'America/New_York',
    'timezone_own': false,
    'timezone_from': null,
  },
  {
    'scope': 'franchise',
    'id': 'fr-2',
    'parent_id': 'co-1',
    'name': 'Texas',
    'hours': _week(_day('08:00', '18:00'), saturday: _day('08:00', '18:00')),
    'hours_own': true,
    'hours_from': 'Texas',
    'timezone': 'America/Chicago',
    'timezone_own': true,
    'timezone_from': 'Texas',
  },
  {
    'scope': 'company',
    'id': 'co-2',
    'parent_id': 'org-1',
    'name': 'Harbor Air',
    'hours': _default,
    'hours_own': false,
    'hours_from': null,
    'timezone': 'America/New_York',
    'timezone_own': false,
    'timezone_from': null,
  },
];

void main() {
  group('the hours summary', () {
    test('groups consecutive days with the same times', () {
      expect(
        officeHoursSummary(
          _week(_day('08:00', '17:00'), saturday: _day('09:00', '13:00')),
        ),
        'Mon–Fri 8:00–17:00 · Sat 9:00–13:00',
      );
      expect(
        officeHoursSummary(
          _week(_day('08:00', '18:00'), saturday: _day('08:00', '18:00')),
        ),
        'Mon–Sat 8:00–18:00',
      );
    });

    test('lists days apart when a day between them differs', () {
      final week = _week(_day('08:00', '17:00'))
        ..['wednesday'] = null
        ..['sunday'] = _day('10:00', '14:00');
      expect(
        officeHoursSummary(week),
        'Mon–Tue 8:00–17:00 · Thu–Fri 8:00–17:00 · Sun 10:00–14:00',
      );
    });

    test('a week with no open day is Closed', () {
      expect(officeHoursSummary(_week(null)), 'Closed');
      expect(officeHoursSummary(null), 'Closed');
    });
  });

  testWidgets('an inherited value is grey italics with where it comes from', (
    tester,
  ) async {
    await _pump(tester);

    final inherited = tester.widget<Text>(
      find.byKey(const ValueKey('office-hours-fr-1')),
    );
    expect(
      inherited.data,
      'Mon–Fri 7:00–19:00 · Sat 9:00–13:00 · from Harbor Home',
    );
    expect(inherited.style?.fontStyle, FontStyle.italic);

    final own = tester.widget<Text>(
      find.byKey(const ValueKey('office-hours-co-1')),
    );
    expect(own.data, 'Mon–Fri 7:00–19:00 · Sat 9:00–13:00');
    expect(own.style?.fontStyle, isNot(FontStyle.italic));

    final zone = tester.widget<Text>(
      find.byKey(const ValueKey('office-zone-fr-2')),
    );
    expect(zone.data, 'Central (Chicago)', reason: 'the Settings label');
    expect(find.text('own hours + zone'), findsOneWidget);
    expect(find.text('own hours'), findsOneWidget);
  });

  testWidgets('places with no time set up are highlighted, and the banner '
      'counts them', (tester) async {
    await _pump(tester);

    expect(
      find.text(
        "2 places still use the default Mon–Fri 8:00–17:00 Eastern. "
        "Customers writing after hours there are told you're closed.",
      ),
      findsOneWidget,
    );
    expect(find.widgetWithText(StatusPill, 'Not set up'), findsNWidgets(2));

    final context = tester.element(
      find.byKey(const ValueKey('office-row-co-2')),
    );
    final caution = ToneColours.of(context, Tone.caution).fill;
    Color? fill(String id) =>
        tester.widget<Container>(find.byKey(ValueKey('office-row-$id'))).color;
    expect(fill('co-2'), caution);
    expect(fill('org-1'), caution);
    expect(fill('fr-1'), isNot(caution), reason: 'it inherits Harbor Home');
  });

  testWidgets('Save sends only what changed', (tester) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-fr-2')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('open-monday')), '9:00');
    await tester.tap(find.byKey(const ValueKey('office-save')));
    await tester.pumpAndSettle();

    expect(_puts, hasLength(1));
    expect(_puts.single.path, endsWith('/leads/office-hours/franchise/fr-2'));
    expect(_puts.single.body.keys, ['hours'], reason: 'the zone is unchanged');
    final hours = _puts.single.body['hours'] as Map;
    expect(hours['monday'], {'open': '09:00', 'close': '18:00'});
    expect(hours['tuesday'], {'open': '08:00', 'close': '18:00'});
    expect(hours['sunday'], isNull);
    expect(find.byKey(const ValueKey('office-editor')), findsNothing);
  });

  testWidgets('nothing changed is nothing sent', (tester) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-co-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('office-save')));
    await tester.pumpAndSettle();

    expect(_puts, isEmpty);
    expect(find.byKey(const ValueKey('office-editor')), findsNothing);
  });

  testWidgets("Use the parent's hours or zone again sends null", (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-fr-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.text("Use Harbor Home's hours again"));
    await tester.pumpAndSettle();

    expect(_puts.single.body, {'hours': null});

    await tester.tap(find.byKey(const ValueKey('office-edit-fr-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.text("Use Harbor Home's time zone again"));
    await tester.pumpAndSettle();

    expect(_puts.last.body, {'timezone': null});
  });

  testWidgets('a place with nothing of its own offers no way back', (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-fr-1')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('inherit-hours')), findsNothing);
    expect(find.byKey(const ValueKey('inherit-zone')), findsNothing);
  });

  testWidgets('Copy Monday to weekdays copies Monday to Tuesday–Friday only', (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-co-1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('open-monday')), '06:30');
    await tester.enterText(find.byKey(const ValueKey('close-monday')), '15:00');
    await tester.tap(find.byKey(const ValueKey('copy-monday')));
    await tester.pumpAndSettle();

    String text(String key) =>
        tester.widget<TextField>(find.byKey(ValueKey(key))).controller!.text;
    for (final day in ['tuesday', 'wednesday', 'thursday', 'friday']) {
      expect(text('open-$day'), '06:30');
      expect(text('close-$day'), '15:00');
    }
    expect(text('open-saturday'), '09:00');
  });

  testWidgets('the day name switches a day between open and Closed', (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-fr-2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('closed-sunday')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('day-sunday')));
    await tester.tap(find.byKey(const ValueKey('day-monday')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('closed-sunday')), findsNothing);
    expect(find.byKey(const ValueKey('open-sunday')), findsOneWidget);
    expect(find.byKey(const ValueKey('closed-monday')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('office-save')));
    await tester.pumpAndSettle();

    final hours = _puts.single.body['hours'] as Map;
    expect(hours['monday'], isNull);
    expect(hours['sunday'], {'open': '08:00', 'close': '17:00'});
  });

  testWidgets('a refused save shows the server sentence under the week', (
    tester,
  ) async {
    await _pump(tester, refuse: 'Monday closes before it can be read.');

    await tester.tap(find.byKey(const ValueKey('office-edit-co-1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('open-monday')), '06:00');
    await tester.tap(find.byKey(const ValueKey('office-save')));
    await tester.pumpAndSettle();

    expect(
      tester.widget<Text>(find.byKey(const ValueKey('office-error'))).data,
      'Monday closes before it can be read.',
    );
    expect(find.byKey(const ValueKey('office-editor')), findsOneWidget);
  });

  testWidgets('a time that is not one is caught before sending', (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-co-1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('close-friday')), '25:00');
    await tester.tap(find.byKey(const ValueKey('office-save')));
    await tester.pumpAndSettle();

    expect(_puts, isEmpty);
    expect(
      find.textContaining("Friday's times need to be written"),
      findsOneWidget,
    );
  });

  testWidgets('Used by names each auto-reply and opens it', (tester) async {
    await _pump(tester);

    expect(find.text('Used by 2 auto-replies:'), findsOneWidget);
    expect(find.text('After-hours text'), findsOneWidget);
    expect(find.text('Weekend reply (off)'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('used-by-rule-2')));
    await tester.pumpAndSettle();

    expect(_opened, ['automations']);
    final link = DeepLinks.take('automations');
    expect(link?.type, 'automation');
    expect(link?.id, 'rule-2');
  });

  testWidgets('one row is open at a time', (tester) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('office-edit-co-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('office-edit-fr-2')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('office-editor')), findsOneWidget);
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('open-monday')))
          .controller!
          .text,
      '08:00',
      reason: "Texas's week, not Harbor Home's 07:00",
    );
    expect(find.text('Close'), findsOneWidget);
  });

  testWidgets('the week fits at the narrowest desk width', (tester) async {
    await _pump(tester, size: const Size(600, 900));

    await tester.tap(find.byKey(const ValueKey('office-edit-fr-2')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('office-editor')), findsOneWidget);
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('day-sunday'))).dy,
      tester.getTopLeft(find.byKey(const ValueKey('day-monday'))).dy,
      reason: 'seven boxes on one line',
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('a phone: nested cards, the editor full screen, two days a row', (
    tester,
  ) async {
    final deeper = <bool>[];
    await _pump(tester, size: const Size(390, 844), onDeeper: deeper.add);

    expect(find.byKey(const ValueKey('office-card-fr-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('office-hours-banner')), findsOneWidget);
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('office-card-fr-2'))).dx,
      greaterThan(
        tester.getTopLeft(find.byKey(const ValueKey('office-card-co-1'))).dx,
      ),
      reason: 'a branch sits inside its company',
    );
    expect(tester.takeException(), isNull);

    await tester.tap(find.byKey(const ValueKey('office-card-fr-2')));
    await tester.pumpAndSettle();

    expect(deeper, [true]);
    expect(find.byKey(const ValueKey('office-card-co-1')), findsNothing);
    final monday = tester.getTopLeft(find.byKey(const ValueKey('day-monday')));
    final tuesday = tester.getTopLeft(
      find.byKey(const ValueKey('day-tuesday')),
    );
    final wednesday = tester.getTopLeft(
      find.byKey(const ValueKey('day-wednesday')),
    );
    expect(tuesday.dy, monday.dy);
    expect(tuesday.dx, greaterThan(monday.dx));
    expect(wednesday.dy, greaterThan(monday.dy));
    expect(tester.takeException(), isNull);

    await tester.tap(find.byTooltip('Back to office hours'));
    await tester.pumpAndSettle();

    expect(deeper, [true, false]);
    expect(find.byKey(const ValueKey('office-card-co-1')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  Size size = const Size(1400, 900),
  String? refuse,
  ValueChanged<bool>? onDeeper,
}) async {
  _puts.clear();
  _opened.clear();
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (request.method == 'PUT') {
        _puts.add((
          path: path,
          body: (jsonDecode(request.body) as Map).cast<String, dynamic>(),
        ));
        if (refuse != null) return _json({'message': refuse}, 422);
        return _json({'data': _places()[1]});
      }
      if (path.endsWith('/leads/office-hours')) {
        return _json({
          'data': {
            'places': _places(),
            'used_by': [
              {'id': 'rule-1', 'name': 'After-hours text', 'active': true},
              {'id': 'rule-2', 'name': 'Weekend reply', 'active': false},
            ],
          },
        });
      }
      if (path.endsWith('/settings')) {
        return _json({
          'data': [
            {
              'key': 'timezone',
              'options': [
                {'value': 'America/New_York', 'label': 'Eastern — New York'},
                {'value': 'America/Chicago', 'label': 'Central — Chicago'},
              ],
            },
          ],
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: OpenDestination(
        open: _opened.add,
        child: Scaffold(
          body: LeadsOfficeHours(api: api, onDeeper: onDeeper),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
