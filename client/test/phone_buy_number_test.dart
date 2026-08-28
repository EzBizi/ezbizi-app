import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/buy_number_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Phone › Settings › Numbers › Buy a new line.
///
/// The thing worth pinning down is that the filters somebody typed actually
/// reach Twilio. A dropped filter is not visible on screen — the search just
/// comes back with numbers in the wrong town, which reads as Twilio having
/// nothing rather than as EzBizi losing the question.
void main() {
  testWidgets('the panel is a button until it is opened', (tester) async {
    await _pump(tester);

    expect(find.text('Buy a new line'), findsOneWidget);
    expect(find.text('Search'), findsNothing);
  });

  testWidgets('every filter typed reaches the API', (tester) async {
    final asked = <Uri>[];
    await _pump(tester, onGet: asked.add);

    await _open(tester);

    await tester.enterText(_field('Area code'), '561');
    await tester.enterText(_field('Contains'), '2468');
    await tester.enterText(_field('City'), 'Jupiter');
    await tester.enterText(_field('State'), 'FL');

    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    final search = asked.lastWhere((u) => u.path.endsWith('/numbers/available'));
    final q = search.queryParameters;

    expect(q['scope_type'], 'organization');
    expect(q['scope_id'], 'org-1');
    expect(q['area_code'], '561');
    expect(q['contains'], '2468');
    expect(q['in_locality'], 'Jupiter');
    expect(q['in_region'], 'FL');

    // Defaults, not accidents: a business line answers calls and takes texts.
    expect(q['voice'], '1');
    expect(q['sms'], '1');
    expect(q['type'], 'local');
  });

  testWidgets('a hit shows what it can do and what it would need', (
    tester,
  ) async {
    await _pump(
      tester,
      available: [
        {
          'phone_number': '+15615552468',
          'locality': 'Jupiter',
          'region': 'FL',
          'address_requirements': 'local',
          'capabilities': {'voice': true, 'sms': true, 'mms': false},
        },
      ],
    );

    await _open(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    expect(find.text('(561) 555-2468'), findsOneWidget);
    expect(find.text('Jupiter, FL'), findsOneWidget);
    expect(find.widgetWithText(Chip, 'Calls'), findsOneWidget);
    expect(find.widgetWithText(Chip, 'Texts'), findsOneWidget);
    expect(find.widgetWithText(Chip, 'Picture messages'), findsNothing);

    // Twilio lists this and would then refuse to sell it.
    expect(find.text('Needs a registered address'), findsOneWidget);
  });

  testWidgets('buying confirms first, then posts the chosen number', (
    tester,
  ) async {
    Map<String, dynamic>? posted;
    var reloaded = false;

    await _pump(
      tester,
      onPost: (body) => posted = body,
      onBought: () async => reloaded = true,
      available: [
        {
          'phone_number': '+15615552468',
          'locality': 'Jupiter',
          'region': 'FL',
          'address_requirements': 'none',
          'capabilities': {'voice': true, 'sms': true},
        },
      ],
    );

    await _open(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    await tester.tap(_rowBuy);
    await tester.pumpAndSettle();

    // Money does not move on one tap — and the confirmation is in the row,
    // not a modal. House rule: no modals, ever.
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.textContaining('starts its monthly charge'), findsOneWidget);
    expect(posted, isNull);

    await tester.enterText(_field('Call it'), 'Dispatch');
    await tester.tap(find.widgetWithText(FilledButton, 'Buy this number'));
    await tester.pumpAndSettle();

    expect(posted?['e164'], '+15615552468');
    expect(posted?['label'], 'Dispatch');
    expect(posted?['scope_id'], 'org-1');
    expect(reloaded, isTrue);

    // Bought is no longer for sale.
    expect(find.text('(561) 555-2468'), findsNothing);
  });

  testWidgets('cancelling buys nothing', (tester) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      onPost: (body) => posted = body,
      available: [
        {
          'phone_number': '+15615552468',
          'locality': 'Jupiter',
          'region': 'FL',
          'address_requirements': 'none',
          'capabilities': {'voice': true},
        },
      ],
    );

    await _open(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    await tester.tap(_rowBuy);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(TextButton, 'Cancel'));
    await tester.pumpAndSettle();

    expect(posted, isNull);
    expect(find.text('(561) 555-2468'), findsOneWidget);
  });

  testWidgets('nothing found says so rather than showing an empty box', (
    tester,
  ) async {
    await _pump(tester, available: const []);

    await _open(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Twilio has nothing matching that'), findsOneWidget);
  });

  testWidgets('a rate center asks for its LATA only under More options', (
    tester,
  ) async {
    await _pump(tester);
    await _open(tester);

    expect(find.text('LATA'), findsNothing);

    await tester.tap(find.widgetWithText(TextButton, 'More options'));
    await tester.pumpAndSettle();

    expect(find.text('LATA'), findsOneWidget);
    expect(find.text('Rate center'), findsOneWidget);
  });
}

Finder _field(String label) => find.ancestor(
  of: find.text(label),
  matching: find.byType(TextField),
);

/// The result row's own Buy, which opens the confirmation rather than buying.
final Finder _rowBuy = find.descendant(
  of: find.byType(Card),
  matching: find.widgetWithText(FilledButton, 'Buy'),
);

Future<void> _open(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(OutlinedButton, 'Buy a new line'));
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  List<Map<String, Object?>>? available,
  void Function(Uri url)? onGet,
  void Function(Map<String, dynamic> body)? onPost,
  Future<void> Function()? onBought,
}) async {
  tester.view.physicalSize = const Size(1280, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (request.method == 'GET') {
        onGet?.call(request.url);
      }

      if (path.endsWith('/phone/numbers/countries')) {
        return _json({
          'data': [
            {'country_code': 'US', 'country': 'United States'},
            {'country_code': 'CA', 'country': 'Canada'},
          ],
        });
      }

      if (path.endsWith('/phone/numbers/available')) {
        return _json({'data': available ?? const []});
      }

      if (path.endsWith('/phone/numbers') && request.method == 'POST') {
        onPost?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': <String, Object?>{}}, 201);
      }

      return _json({'data': <Object?>[]});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: BuyNumberPanel(
            api: api,
            scopeType: 'organization',
            scopeId: 'org-1',
            onBought: onBought ?? () async {},
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) =>
    http.Response(jsonEncode(body), status, headers: const {
      'content-type': 'application/json',
    });
