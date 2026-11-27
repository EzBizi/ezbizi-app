import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/locations_pane.dart';
import 'package:ezbizi_client/widgets/formatted_notes.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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

    // The directory: every branch, by state, with one State picker (UI queue
    // 64; it was 26 chips).
    expect(find.byKey(const Key('locations-row-l-3')), findsOneWidget);
    await _pickState(tester, 'Illinois · 1');
    expect(find.byKey(const Key('locations-row-l-1')), findsNothing);
    expect(find.byKey(const Key('locations-row-l-3')), findsOneWidget);
    await _pickState(tester, 'All states · 3');

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

    // The lookup first, the list under it (UI queue 64, phone 4).
    expect(
      tester.getTopLeft(find.byKey(const Key('locations-lookup'))).dy,
      lessThan(
        tester.getTopLeft(find.byKey(const Key('locations-row-l-1'))).dy,
      ),
    );
    await tester.enterText(find.byKey(const Key('locations-lookup')), '44139');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-answer-l-2')), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Call'), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('locations-row-l-3')));
    await tester.tap(find.byKey(const Key('locations-row-l-3')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-detail-l-3')), findsOneWidget);
    expect(find.byKey(const Key('locations-lookup')), findsNothing);

    await tester.tap(find.byKey(const Key('locations-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-lookup')), findsOneWidget);
  });

  // ── UI queue 64: made easier to read (Jose: "make this page look better") ──

  testWidgets('a row names the branch once, with its company and city small '
      'under it, and states read in full', (tester) async {
    await _pump(tester, const Size(1400, 900), {});

    final row = find.byKey(const Key('locations-row-l-1'));
    expect(
      find.descendant(of: row, matching: find.text('Cleveland')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: row,
        matching: find.text('HARBOR CHIMNEY · Warrensville Heights'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: row, matching: find.text('(216) 555-0142')),
      findsOneWidget,
    );
    expect(find.textContaining('Harbor Chimney · Cleveland'), findsNothing);

    expect(
      find.descendant(
        of: find.byKey(const Key('locations-state-OH')),
        matching: find.text('Ohio'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('locations-count')), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const Key('locations-count'))).data,
      '3 branches',
    );
  });

  testWidgets('the chosen branch is shaded, and its card acts on each fact', (
    tester,
  ) async {
    final copied = <String>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied.add((call.arguments as Map)['text'] as String);
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );
    await _pump(tester, const Size(1400, 900), {});

    Color? shade(String id) => tester
        .widget<Material>(
          find
              .ancestor(
                of: find.byKey(Key('locations-row-$id')),
                matching: find.byType(Material),
              )
              .first,
        )
        .color;
    // Before any pick, the card shows the first branch listed (Illinois
    // before Ohio), and that is the row shaded.
    expect(find.byKey(const Key('locations-detail-l-3')), findsOneWidget);
    expect(shade('l-3'), isNot(Colors.transparent));
    expect(shade('l-1'), Colors.transparent);

    await tester.tap(find.byKey(const Key('locations-row-l-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('locations-detail-l-1')), findsOneWidget);
    expect(shade('l-1'), isNot(Colors.transparent));
    expect(shade('l-3'), Colors.transparent);

    await tester.tap(find.byKey(const Key('locations-row-l-3')));
    await tester.pumpAndSettle();

    final card = find.byKey(const Key('locations-detail-l-3'));
    await tester.tap(
      find.descendant(
        of: card,
        matching: find.byTooltip('Copy the phone number'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(of: card, matching: find.byTooltip('Copy the address')),
    );
    await tester.pumpAndSettle();
    expect(copied, ['(312) 555-0133', '1 Main St, Chicago, IL 60642']);
    expect(
      find.descendant(of: card, matching: find.byTooltip('Directions')),
      findsOneWidget,
    );
  });

  // Scheduling: `state` is whatever the website row holds, not always a
  // postal code, so a state that is not one is shown as written.
  testWidgets('a state that is not a postal code is shown as written', (
    tester,
  ) async {
    await _pump(
      tester,
      const Size(1400, 900),
      {},
      locations: [
        ..._locations,
        _location(
          'l-9',
          'Lakeshore',
          'Toronto',
          ' Ontario ',
          'M5V',
          '(416) 555-0199',
        ),
      ],
    );

    expect(
      find.descendant(
        of: find.byKey(const Key('locations-state-Ontario')),
        matching: find.text('Ontario'),
      ),
      findsOneWidget,
    );
    await _pickState(tester, 'Ontario · 1');
    expect(find.byKey(const Key('locations-row-l-9')), findsOneWidget);
    expect(find.byKey(const Key('locations-row-l-1')), findsNothing);
  });

  testWidgets('on a phone each branch can be called from its row', (
    tester,
  ) async {
    await _pump(tester, const Size(390, 844), {});

    expect(
      find.descendant(
        of: find.byKey(const Key('locations-row-l-2')),
        matching: find.byTooltip('Call Akron'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('locations-row-l-2')),
        matching: find.text('(330) 555-0177'),
      ),
      findsOneWidget,
    );
    // Jose, 11-24: on a phone the address sits under the branch name.
    expect(
      find.descendant(
        of: find.byKey(const Key('locations-row-l-2')),
        matching: find.text('1 Main St, Akron, OH 44308'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('each branch shows its street address in its own column, '
      'muted when it has none', (tester) async {
    final none = Map<String, Object?>.of(_locations[2])
      ..['address_line1'] = null
      ..['city'] = null
      ..['state'] = null
      ..['postal_code'] = null;
    await _pump(
      tester,
      const Size(1400, 900),
      {},
      locations: [_locations[0], _locations[1], none],
    );

    final columnTitle = find.descendant(
      of: find
          .ancestor(of: find.text('BRANCH'), matching: find.byType(Row))
          .first,
      matching: find.text('ADDRESS'),
    );
    expect(columnTitle, findsOneWidget);
    final header = tester.getTopLeft(columnTitle).dx;
    expect(header, greaterThan(tester.getTopLeft(find.text('BRANCH')).dx));
    expect(
      header,
      lessThan(
        tester
            .getTopLeft(
              find.descendant(
                of: find
                    .ancestor(
                      of: find.text('BRANCH'),
                      matching: find.byType(Row),
                    )
                    .first,
                matching: find.text('SERVES'),
              ),
            )
            .dx,
      ),
    );
    expect(
      tester.widget<Text>(find.byKey(const Key('locations-address-l-1'))).data,
      '1 Main St, Warrensville Heights, OH 44128',
    );
    final missing = tester.widget<Text>(
      find.byKey(const Key('locations-address-l-3')),
    );
    expect(missing.data, 'No address');
    expect(missing.style?.fontStyle, FontStyle.italic);
    expect(tester.takeException(), isNull);
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
                {
                  'id': 'co-1',
                  'name': 'Harbor Chimney',
                  'parent_company_id': null,
                },
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
                {
                  'company_id': 'co-1',
                  'text': 'Office lead: Dana Kim, ext 204',
                },
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
                {
                  'id': 'co-1',
                  'name': 'Harbor Chimney',
                  'parent_company_id': null,
                },
              ],
            },
          });
        }
        if (path.endsWith('/scheduling/locations')) {
          return _json({
            'data': _locations,
            'meta': {
              'notes': [
                {
                  'company_id': 'co-1',
                  'text': 'Office lead: Dana Kim, ext 204',
                },
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

    // Read, not offered: the saved note as text, no field.
    expect(
      tester
          .widget<FormattedNotesText>(
            find.byKey(const Key('dispatcher-note-co-1')),
          )
          .markup,
      'Office lead: Dana Kim, ext 204',
    );
    expect(find.byType(TextField), findsNothing);
    expect(find.byKey(const Key('dispatcher-note-save-co-1')), findsNothing);
    expect(
      find.text('Changing them takes the right to edit Scheduling.'),
      findsOneWidget,
    );
  });
}

Future<void> _pickState(WidgetTester tester, String label) async {
  await tester.tap(find.byKey(const Key('locations-state')));
  await tester.pumpAndSettle();
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester,
  Size size,
  Map<String, Map<String, String>> asked, {
  List<Map<String, Object?>>? locations,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/scheduling/locations')) {
        return _json({
          'data': locations ?? _locations,
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
  _location(
    'l-1',
    'Cleveland',
    'Warrensville Heights',
    'OH',
    '44128',
    '(216) 555-0142',
  ),
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
