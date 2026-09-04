import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/sms_codes_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The consent form, checked against the list a carrier actually applies.
///
/// This card is the evidence attached to a toll-free verification: Twilio asks
/// for a screenshot of the exact point where somebody agrees to be texted, and
/// names what has to be visible in it. If one of these disappears in a tidy-up,
/// nothing breaks on screen and the next filing is refused — months later, by
/// somebody who has never seen this file.
///
/// So the assertions below are deliberately about WORDS ON A SCREEN. That is
/// unusual and it is the point: the words are the feature.
void main() {
  testWidgets('the form carries every element a carrier requires', (
    tester,
  ) async {
    await _pump(tester);

    // 1 — somewhere to put the number.
    expect(find.byKey(const Key('sms-codes-number')), findsOneWidget);

    // 2 — a tick box.
    expect(find.byKey(const Key('sms-codes-consent')), findsOneWidget);

    // 3 — what the messages actually are.
    expect(find.textContaining('one-time code'), findsWidgets);

    // 4 — how often they arrive.
    expect(find.textContaining('Message frequency'), findsOneWidget);

    // 5 — the rates disclaimer.
    expect(find.textContaining('Message and data rates may apply'), findsOneWidget);

    // 6 — HELP and STOP, both of them.
    expect(find.textContaining('Reply HELP'), findsOneWidget);
    expect(find.textContaining('Reply STOP'), findsOneWidget);

    // 7 — the two links.
    expect(find.text('Terms of Service'), findsOneWidget);
    expect(find.text('Privacy Policy'), findsOneWidget);

    // 8 — a button that says what pressing it does.
    expect(find.text('Yes, text me my codes'), findsOneWidget);
  });

  /// The single thing most likely to get a filing rejected on sight.
  ///
  /// A pre-ticked box is not consent in any jurisdiction that has an opinion
  /// about it, and it is the first thing a reviewer looks for in the
  /// screenshot.
  testWidgets('the consent box starts unticked', (tester) async {
    await _pump(tester);

    final box = tester.widget<CheckboxListTile>(
      find.byKey(const Key('sms-codes-consent')),
    );

    expect(
      box.value,
      isFalse,
      reason: 'the consent box is pre-ticked, which is not consent',
    );
  });

  /// And you cannot press past it.
  ///
  /// A submit button that works with the box untouched makes the box
  /// decorative, which is worse than not having one: the screenshot shows a
  /// consent the software does not require.
  testWidgets('submit does nothing until the box is ticked', (tester) async {
    await _pump(tester);

    await tester.enterText(
      find.byKey(const Key('sms-codes-number')),
      '3055550199',
    );
    await tester.pumpAndSettle();

    final before = tester.widget<FilledButton>(
      find.byKey(const Key('sms-codes-submit')),
    );
    expect(
      before.onPressed,
      isNull,
      reason: 'the form could be submitted without consent',
    );

    await tester.tap(find.byKey(const Key('sms-codes-consent')));
    await tester.pumpAndSettle();

    final after = tester.widget<FilledButton>(
      find.byKey(const Key('sms-codes-submit')),
    );
    expect(after.onPressed, isNotNull, reason: 'ticking the box changed nothing');
  });

  /// With no sender configured the form is not drawn at all.
  ///
  /// Taking a number and a consent for a message that cannot be sent records
  /// an agreement nobody can act on, and leaves somebody waiting for a code.
  testWidgets('no sender means no form, and it says why', (tester) async {
    await _pump(tester, available: false);

    expect(find.byKey(const Key('sms-codes-number')), findsNothing);
    expect(find.byKey(const Key('sms-codes-consent')), findsNothing);
    expect(find.textContaining('cannot send text messages yet'), findsOneWidget);
  });
}

Future<void> _pump(WidgetTester tester, {bool available = true}) async {
  tester.view.physicalSize = const Size(900, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me/sms-codes')) {
        return http.Response(
          jsonEncode({
            'data': {
              'available': available,
              'number': null,
              'consented': false,
              'confirmed': false,
              'pending': false,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        jsonEncode({'data': _me}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: SmsCodesCard(api: api, session: session),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@ezbizi.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': <String>[],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'EzBizi',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'EzBizi',
    'path': ['EzBizi'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
