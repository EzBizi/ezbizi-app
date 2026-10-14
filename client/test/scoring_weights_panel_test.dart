import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/scoring_weights_panel.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 97: what the Scoring score weighs, against Reports' 051fa5e6
/// (GET/PUT /reports/scoring/weights; eight whole numbers that total 100).
void main() {
  const labels = {
    'conversion': 'Conversion',
    'greeted': 'Greeted with the company name',
    'got_name': "Got the caller's name",
    'got_address': 'Got the address',
    'found_need': 'Asked what the problem is',
    'offered_time': 'Offered a time',
    'gave_price': 'Explained the price',
    'asked_for_booking': 'Asked to book',
  };
  const defaults = {
    'conversion': 40,
    'greeted': 5,
    'got_name': 10,
    'got_address': 10,
    'found_need': 10,
    'offered_time': 10,
    'gave_price': 5,
    'asked_for_booking': 10,
  };

  late List<Map<String, dynamic>> put;
  bool? popped;

  Future<void> pump(WidgetTester tester, Map<String, int> current) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    put = [];
    popped = null;
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'PUT') {
          put.add(jsonDecode(request.body) as Map<String, dynamic>);
          return http.Response(
            jsonEncode({'data': <String, dynamic>{}}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }
        return http.Response(
          jsonEncode({
            'data': {'weights': current, 'defaults': defaults},
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () async => popped = await showSidePanel<bool>(
                context,
                builder: (_) => ScoringWeightsPanel(api: api, labels: labels),
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
  }

  FilledButton save(WidgetTester tester) => tester.widget<FilledButton>(
    find.byKey(const Key('scoring-weights-save')),
  );

  testWidgets('a total short of 100 says what is left, and Save waits', (
    tester,
  ) async {
    await pump(tester, defaults);

    expect(find.text('✓ 100 of 100'), findsOneWidget);
    // Nothing changed yet: nothing to save.
    expect(save(tester).onPressed, isNull);

    await tester.enterText(
      find.byKey(const ValueKey('scoring-weight-conversion')),
      '35',
    );
    await tester.pump();
    expect(find.text('95 of 100: add 5'), findsOneWidget);
    expect(save(tester).onPressed, isNull);

    await tester.enterText(
      find.byKey(const ValueKey('scoring-weight-greeted')),
      '10',
    );
    await tester.pump();
    expect(find.text('✓ 100 of 100'), findsOneWidget);
    await tester.tap(find.byKey(const Key('scoring-weights-save')));
    await tester.pumpAndSettle();

    expect(put.single['weights'], {
      ...defaults,
      'conversion': 35,
      'greeted': 10,
    });
    expect(popped, isTrue);
  });

  testWidgets('Back to the defaults puts the defaults back', (tester) async {
    await pump(tester, {
      ...defaults,
      'conversion': 20,
      'asked_for_booking': 30,
    });

    expect(
      tester
          .widget<TextField>(
            find.byKey(const ValueKey('scoring-weight-conversion')),
          )
          .controller!
          .text,
      '20',
    );
    await tester.tap(find.byKey(const Key('scoring-weights-defaults')));
    await tester.pump();
    expect(
      tester
          .widget<TextField>(
            find.byKey(const ValueKey('scoring-weight-conversion')),
          )
          .controller!
          .text,
      '40',
    );
    await tester.tap(find.byKey(const Key('scoring-weights-save')));
    await tester.pumpAndSettle();
    expect(put.single['weights'], defaults);
  });
}
