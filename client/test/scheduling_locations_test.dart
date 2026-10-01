import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/locations_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// Jovanni Martin, suggestion 0080: "company addresses with a feature that
// shows the closest location to the inputed address ... as well as contact
// info for newbie dispatchers". UI queue 49, Jose: 1 and 4.
void main() {
  testWidgets('the lookup puts the branch serving the ZIP first, then the '
      'nearest by miles, beside the directory', (tester) async {
    final asked = <String, Map<String, String>>{};
    await _pump(tester, const Size(1400, 900), asked);

    // The directory: every branch, by state, with the state chips.
    expect(find.byKey(const Key('locations-row-l-3')), findsOneWidget);
    await tester.tap(find.widgetWithText(ChoiceChip, 'IL 1'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-row-l-1')), findsNothing);
    expect(find.byKey(const Key('locations-row-l-3')), findsOneWidget);
    await tester.tap(find.widgetWithText(ChoiceChip, 'All 3'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('locations-lookup')),
      '18 Maple Ct, Solon',
    );
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(asked['nearest'], {'q': '18 Maple Ct, Solon'});

    // Akron serves 44139, so it leads although Cleveland is nearer.
    final first = find.byKey(const Key('locations-answer-l-2'));
    expect(
      find.descendant(of: first, matching: find.text('Serves 44139')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: first, matching: find.text('Closest')),
      findsOneWidget,
    );
    expect(
      tester.getTopLeft(first).dx,
      lessThan(
        tester.getTopLeft(find.byKey(const Key('locations-answer-l-1'))).dx,
      ),
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('locations-answer-l-1')),
        matching: find.text('6.2 mi'),
      ),
      findsOneWidget,
    );

    // The answer opens beside the list, with its company's note.
    expect(find.byKey(const Key('locations-detail-l-2')), findsOneWidget);
    expect(find.text('Office lead: Dana Kim, ext 204'), findsOneWidget);

    // Availability for the typed ZIP, in a panel as Leads opens it.
    await tester.tap(find.byKey(const Key('locations-availability')));
    await tester.pumpAndSettle();
    expect(asked['availability'], {'zip': '44139'});
  });

  testWidgets('on a phone the lookup comes first and a branch opens in place, '
      'with its own way back', (tester) async {
    final asked = <String, Map<String, String>>{};
    await _pump(tester, const Size(390, 844), asked);

    expect(find.byKey(const Key('locations-row-l-1')), findsNothing);
    await tester.enterText(find.byKey(const Key('locations-lookup')), '44139');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-answer-l-2')), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Call'), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('locations-all')));
    await tester.tap(find.byKey(const Key('locations-all')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('locations-row-l-3')));
    await tester.tap(find.byKey(const Key('locations-row-l-3')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-detail-l-3')), findsOneWidget);
    expect(find.byKey(const Key('locations-lookup')), findsNothing);

    await tester.tap(find.byKey(const Key('locations-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-lookup')), findsOneWidget);
  });

  testWidgets("Settings keeps each company's note for new dispatchers", (
    tester,
  ) async {
    const canEdit = true;
    Map<String, dynamic>? put;
    String? putPath;
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': [
                ...(_me['permissions']! as List),
                if (canEdit) 'scheduling.edit',
              ],
              'companies': [
                {'id': 'co-1', 'name': 'Harbor Chimney', 'parent_company_id': null},
              ],
            },
          });
        }
        if (request.method == 'PUT') {
          putPath = path;
          put = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': put});
        }
        if (path.endsWith('/scheduling/locations')) {
          return _json({
            'data': _locations,
            'meta': {
              'notes': [
                {'company_id': 'co-1', 'text': 'Office lead: Dana Kim, ext 204'},
              ],
            },
          });
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: DispatcherNotesSettings(
            api: api,
            session: session,
            visible: true,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final box = find.byKey(const Key('dispatcher-note-co-1'));
    expect(
      tester.widget<TextField>(box).controller!.text,
      'Office lead: Dana Kim, ext 204',
    );
    FilledButton save() => tester.widget<FilledButton>(
      find.byKey(const Key('dispatcher-note-save-co-1')),
    );
    expect(save().onPressed, isNull, reason: 'nothing changed yet');

    await tester.enterText(box, 'After 6 PM: (216) 555-0100');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('dispatcher-note-save-co-1')));
    await tester.pumpAndSettle();
    expect(putPath, endsWith('/scheduling/locations/notes/co-1'));
    expect(put, {'text': 'After 6 PM: (216) 555-0100'});
    expect(find.text('Saved.'), findsOneWidget);
  });

  // Saving one also takes scheduling.edit (Scheduling, 6b7c282f).
  testWidgets('without the edit grant the notes are read, not offered', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'companies': [
                {'id': 'co-1', 'name': 'Harbor Chimney', 'parent_company_id': null},
              ],
            },
          });
        }
        if (path.endsWith('/scheduling/locations')) {
          return _json({
            'data': _locations,
            'meta': {
              'notes': [
                {'company_id': 'co-1', 'text': 'Office lead: Dana Kim, ext 204'},
              ],
            },
          });
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: DispatcherNotesSettings(
            api: api,
            session: session,
            visible: true,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final box = tester.widget<TextField>(
      find.byKey(const Key('dispatcher-note-co-1')),
    );
    expect(box.controller!.text, 'Office lead: Dana Kim, ext 204');
    expect(box.readOnly, isTrue);
    expect(find.byKey(const Key('dispatcher-note-save-co-1')), findsNothing);
    expect(
      find.text('Changing them takes the right to edit Scheduling.'),
      findsOneWidget,
    );
  });
}

Future<void> _pump(
  WidgetTester tester,
  Size size,
  Map<String, Map<String, String>> asked,
) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/scheduling/locations')) {
        return _json({
          'data': _locations,
          'meta': {
            'notes': [
              {
                'company_id': 'co-1',
                'company_name': 'Harbor Chimney',
                'text': 'Office lead: Dana Kim, ext 204',
              },
            ],
          },
        });
      }
      if (path.endsWith('/scheduling/locations/nearest')) {
        asked['nearest'] = request.url.queryParameters;
        return _json({
          'data': {
            'place': {
              'label': '18 Maple Ct, Solon, OH 44139',
              'latitude': 41.38,
              'longitude': -81.44,
              'postal_code': '44139',
            },
            'serving': {
              'location_id': 'l-2',
              'area': {'id': 'ar-2', 'name': 'Akron area'},
            },
            'nearest': [
              {'location_id': 'l-1', 'miles': 6.2},
              {'location_id': 'l-2', 'miles': 23.8},
              {'location_id': 'l-3', 'miles': 309.4},
            ],
          },
        });
      }
      if (path.endsWith('/scheduling/availability')) {
        asked['availability'] = request.url.queryParameters;
        return _json({
          'data': {'areas': <Object>[]},
        });
      }
      return _json({'data': <Object>[]});
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LocationsPane(api: api, session: session, visible: true),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> _location(
  String id,
  String name,
  String city,
  String state,
  String zip,
  String phone,
) => {
  'id': id,
  'name': name,
  'company': {'id': 'co-1', 'name': 'Harbor Chimney'},
  'address_line1': '1 Main St',
  'address_line2': null,
  'city': city,
  'state': state,
  'postal_code': zip,
  'phone': phone,
  'email': null,
  'maps_url': null,
  'latitude': 41.0,
  'longitude': -81.0,
  'service_area': '$city area',
  'area': null,
};

final _locations = [
  _location('l-1', 'Cleveland', 'Warrensville Heights', 'OH', '44128', '(216) 555-0142'),
  _location('l-2', 'Akron', 'Akron', 'OH', '44308', '(330) 555-0177'),
  _location('l-3', 'Chicago', 'Chicago', 'IL', '60642', '(312) 555-0133'),
];

const _me = {
  'id': 'u-1',
  'name': 'Sam Rivera',
  'email': 'sam@example.com',
  'role': {'name': 'Dispatcher'},
  'has_avatar': false,
  'permissions': ['scheduling.locations.view', 'scheduling.availability.view'],
  'modules': <Object>[],
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
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
