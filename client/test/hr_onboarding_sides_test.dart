import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/contracts.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/hr/onboarding_form.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The onboarding form split into an Employer tab and an Employee tab (design
/// 135, section 7). Every name here is invented.
void main() {
  testWidgets('the employer opens on Employer, the new hire on Employee', (
    tester,
  ) async {
    await _pump(tester, parties: ['employer']);
    expect(_labels(tester), ['Employer · 1 left', 'Employee · 2 left']);
    expect(_selected(tester), 0);
    expect(find.byKey(answerKey('wage')), findsOneWidget);
    expect(find.byKey(answerKey('bank')), findsNothing);

    await _pump(tester, parties: ['employee']);
    expect(_selected(tester), 1);
    expect(find.byKey(answerKey('bank')), findsOneWidget);
    expect(find.byKey(answerKey('wage')), findsNothing);
  });

  testWidgets('an either question is on the reader own tab, marked, and on '
      'Employer for somebody who is neither', (tester) async {
    await _pump(tester, parties: ['employee']);
    expect(find.byKey(answerKey('start_day')), findsOneWidget);
    expect(find.text('Either of you can answer'), findsOneWidget);
    await _side(tester, 'Employer');
    expect(find.byKey(answerKey('start_day')), findsNothing);

    await _pump(tester, parties: ['employer']);
    expect(find.byKey(answerKey('start_day')), findsOneWidget);
    await _side(tester, 'Employee');
    expect(find.byKey(answerKey('start_day')), findsNothing);

    // Neither party: a reader of the record.
    await _pump(tester, parties: const []);
    expect(_selected(tester), 0);
    expect(find.byKey(answerKey('start_day')), findsOneWidget);
    expect(find.byKey(const Key('onboarding-other-side')), findsOneWidget);
  });

  testWidgets('the other side is read-only, greyed, and waiting on them', (
    tester,
  ) async {
    await _pump(tester, parties: ['employer']);
    expect(find.byKey(const Key('onboarding-other-side')), findsNothing);

    await _side(tester, 'Employee');
    expect(find.byKey(const Key('onboarding-other-side')), findsOneWidget);
    expect(find.textContaining('Waiting on Sam Ortiz.'), findsOneWidget);
    expect(find.text('NOT YOURS TO ANSWER'), findsOneWidget);
    final bank = tester.widget<TextField>(find.byKey(answerKey('bank')));
    expect(bank.enabled, isFalse);
    final greyed = find.ancestor(
      of: find.byKey(answerKey('bank')),
      matching: find.byType(Opacity),
    );
    expect(tester.widget<Opacity>(greyed.first).opacity, lessThan(1));

    // The new hire reading the employer's half waits on who started it.
    await _pump(tester, parties: ['employee']);
    await _side(tester, 'Employer');
    expect(find.textContaining('Waiting on Lee Park.'), findsOneWidget);

    // Nothing left on that side: no waiting, still only to read.
    await _pump(tester, parties: ['employee'], wageAnswered: true);
    expect(_labels(tester).first, 'Employer · 0 left');
    await _side(tester, 'Employer');
    expect(find.textContaining('Waiting on'), findsNothing);
    expect(find.textContaining('Lee Park’s answers'), findsOneWidget);
  });
}

List<String> _labels(WidgetTester tester) => [
  for (final label
      in tester
          .widget<TabBarLike>(find.byKey(const Key('onboarding-sides')))
          .labels)
    label.$1,
];

int _selected(WidgetTester tester) => tester
    .widget<TabBarLike>(find.byKey(const Key('onboarding-sides')))
    .selected;

Future<void> _side(WidgetTester tester, String side) async {
  await tester.tap(
    find.descendant(
      of: find.byKey(const Key('onboarding-sides')),
      matching: find.textContaining('$side ·'),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  required List<String> parties,
  bool wageAnswered = false,
}) async {
  tester.view.physicalSize = const Size(1400, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final employer = parties.contains('employer');
  final employee = parties.contains('employee');
  final form = {
    'id': 'o-1',
    'status': 'in_progress',
    'person': {'user_id': 'u-2', 'name': 'Sam Ortiz', 'email': 'sam@x.test'},
    'contract': {'name': 'Technician', 'version': 1},
    'started_at': '2026-10-01T09:00:00+00:00',
    'parties': parties,
    'started_by': 'Lee Park',
    'fields': [
      _field('wage', 'Hourly wage', 'employer', employer, wageAnswered),
      _field('bank', 'Bank account', 'employee', employee, false),
      _field('shirt', 'Shirt size', 'employee', employee, false),
      _field('start_day', 'Start day', 'either', employer || employee, false),
    ],
    'missing': {
      if (!wageAnswered) 'wage': 'Hourly wage',
      'bank': 'Bank account',
      'shirt': 'Shirt size',
    },
    'frozen': false,
    'signature_fields': <Object>[],
    'signatures': <Object>[],
  };

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/hr/onboardings/o-1')) {
        return _json({'data': form});
      }
      if (request.url.path.endsWith('/preview')) {
        return _json({
          'data': {'content': '<p>Invented</p>'},
        });
      }
      return _json({'message': 'Unexpected ${request.url.path}'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      key: UniqueKey(),
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: OnboardingForm(
          api: api,
          onboardingId: 'o-1',
          vocabulary: ContractVocabulary.none,
          formats: DisplayFormats.fallback,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> _field(
  String key,
  String label,
  String filledBy,
  bool writable,
  bool answered,
) => {
  'key': key,
  'label': label,
  'type': 'text',
  'options': <String>[],
  'source': 'contract',
  'filled_by': filledBy,
  'required': true,
  'sensitive': false,
  'retired': false,
  'value': answered ? '21' : null,
  'writable': writable,
  'locked_because': null,
  'set_by': null,
  'set_at': null,
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
