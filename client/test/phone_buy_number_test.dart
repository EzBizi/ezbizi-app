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
  /// The search is the screen, not a thing behind a button on it.
  ///
  /// This used to assert the opposite — that the panel was a button until it
  /// was opened — which described the step Jose asked to remove: somebody who
  /// has navigated to Buy a number is asked to say so again, and nothing
  /// behind the button is destructive.
  testWidgets('the search is there without opening anything', (tester) async {
    await _pump(tester);

    expect(find.text('Buy a new line'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Search'), findsOneWidget);
    expect(
      find.widgetWithText(OutlinedButton, 'Buy a new line'),
      findsNothing,
      reason: 'the extra step is back',
    );
  });

  /// The three things Jose named, side by side.
  ///
  /// They are not Twilio's three: a vanity number is a local or toll-free one
  /// searched by what its digits spell. The modes are how the choice is made,
  /// and the type is derived from them.
  testWidgets('local, toll-free and vanity are offered as modes', (
    tester,
  ) async {
    await _pump(tester);

    for (final mode in ['Local number', 'Toll-free', 'Vanity']) {
      expect(
        find.widgetWithText(SegmentedButton<String>, mode),
        findsOneWidget,
        reason: '$mode is not offered',
      );
    }
  });

  /// A toll-free prefix reaches Twilio as an area code, which is what it is.
  testWidgets('a toll-free prefix is searched for', (tester) async {
    final asked = <Uri>[];
    await _pump(tester, onGet: asked.add);

    await _mode(tester, 'Toll-free');
    await _pick(tester, const Key('buy-toll-free-prefix'), '833');
    await _search(tester);

    final q = _lastSearch(asked);
    expect(q['type'], 'toll_free');
    expect(q['area_code'], '833');
  });

  /// A vanity search is letters on whichever kind of number was asked for.
  testWidgets('a vanity search sends the letters and the kind', (tester) async {
    final asked = <Uri>[];
    await _pump(tester, onGet: asked.add);

    await _mode(tester, 'Vanity');
    await tester.enterText(_field('Spells or contains'), 'CHIMNEY');
    await _search(tester);

    final q = _lastSearch(asked);
    expect(q['contains'], 'CHIMNEY');
    expect(
      q['type'],
      'local',
      reason: 'a vanity number is a real kind of number, not a fourth type',
    );
  });

  /// Switching modes does not carry the previous mode's answer along.
  ///
  /// Through VANITY rather than toll-free, and that is the whole point of the
  /// test. Toll-free does not read the area-code field at all — it sends its
  /// prefix instead — so a left-behind 561 could not reach the query even if
  /// nothing cleared it, and the first version of this test passed with the
  /// clearing sabotaged.
  ///
  /// Vanity does read it. A 561 carried in from a local search silently pins
  /// a vanity search to one area code, which comes back nearly empty and
  /// reads as "no numbers spell that".
  testWidgets('switching modes drops the filter that belonged to the last one', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, onGet: asked.add);

    await tester.enterText(_field('Area code'), '561');
    await _mode(tester, 'Vanity');

    expect(
      find.text('Spells or contains'),
      findsOneWidget,
      reason: 'this mode does not show the field the test is about',
    );

    await tester.enterText(_field('Spells or contains'), 'CHIMNEY');
    await _search(tester);

    final q = _lastSearch(asked);
    expect(q['contains'], 'CHIMNEY');
    expect(q['area_code'], isNull, reason: 'the last mode pinned this search');
  });

  /// The state is picked, not remembered.
  testWidgets('a state is chosen from a list and sent as its code', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, onGet: asked.add);

    await _pick(tester, const ValueKey('buy-region-US'), 'Florida');
    await _search(tester);

    expect(_lastSearch(asked)['in_region'], 'FL');
  });

  /// US and Canada are the two this business buys in, so they are the two at
  /// the top — and the rest are a filter away rather than a scroll away.
  testWidgets('the country list leads with the ones we buy in', (tester) async {
    await _pump(tester);

    final menu = tester.widget<DropdownMenu<String>>(
      find.byKey(const Key('buy-country')),
    );

    expect(menu.enableFilter, isTrue, reason: 'ninety countries and no search');
    expect(
      menu.dropdownMenuEntries.take(2).map((e) => e.value),
      ['US', 'CA'],
    );
    expect(menu.initialSelection, 'US');
  });

  testWidgets('every filter typed reaches the API', (tester) async {
    final asked = <Uri>[];
    await _pump(tester, onGet: asked.add);

    await tester.enterText(_field('Area code'), '561');
    await _pick(tester, const ValueKey('buy-region-US'), 'Florida');

    // City is a refinement rather than a question everybody is asked, so it
    // lives under More options now.
    await tester.tap(find.widgetWithText(TextButton, 'More options'));
    await tester.pumpAndSettle();
    await tester.enterText(_field('City'), 'Jupiter');

    await _search(tester);

    final q = _lastSearch(asked);

    expect(q['scope_type'], 'organization');
    expect(q['scope_id'], 'org-1');
    expect(q['area_code'], '561');
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

    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    expect(find.text('+1 (561) 555-2468'), findsOneWidget);
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
    expect(find.text('+1 (561) 555-2468'), findsNothing);
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

    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    await tester.tap(_rowBuy);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(TextButton, 'Cancel'));
    await tester.pumpAndSettle();

    expect(posted, isNull);
    expect(find.text('+1 (561) 555-2468'), findsOneWidget);
  });

  testWidgets('nothing found says so rather than showing an empty box', (
    tester,
  ) async {
    await _pump(tester, available: const []);

    await tester.tap(find.widgetWithText(FilledButton, 'Search'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Twilio has nothing matching that'), findsOneWidget);
  });

  testWidgets('a rate center asks for its LATA only under More options', (
    tester,
  ) async {
    await _pump(tester);
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

Future<void> _search(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(FilledButton, 'Search'));
  await tester.pumpAndSettle();
}

/// What the last search actually asked Twilio for.
///
/// The artefact, not the tap: a filter that is on screen and never reaches
/// the query comes back as numbers in the wrong town, which reads as Twilio
/// having nothing rather than as EzBizi losing the question.
Map<String, String> _lastSearch(List<Uri> asked) => asked
    .lastWhere((u) => u.path.endsWith('/numbers/available'))
    .queryParameters;

Future<void> _mode(WidgetTester tester, String label) async {
  await tester.tap(find.text(label));
  await tester.pumpAndSettle();
}

/// Open a DropdownMenu and choose one of its entries by label.
Future<void> _pick(WidgetTester tester, Key menu, String label) async {
  await tester.tap(find.byKey(menu));
  await tester.pumpAndSettle();

  // The picker opens a dialog, so the label exists twice once it is open — in
  // the field behind and in the list. `.last` is the list.
  await tester.tap(find.text(label).last);
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
        // DELIBERATELY OUT OF ORDER, and with the two we care about buried.
        //
        // The first version of this fake listed US and CA first, which is
        // what the screen is supposed to arrange — so the sorting was never
        // exercised and removing it left every test green.
        return _json({
          'data': [
            {'country_code': 'AU', 'country': 'Australia'},
            {'country_code': 'GB', 'country': 'United Kingdom'},
            {'country_code': 'CA', 'country': 'Canada'},
            {'country_code': 'DE', 'country': 'Germany'},
            {'country_code': 'US', 'country': 'United States'},
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
