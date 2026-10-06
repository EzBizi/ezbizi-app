import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/hr/hr_overview.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  // Apple, 2026-10-07: on an iPad the twelve month columns each overflowed by
  // a fraction of a pixel and their names ran together.
  for (final (width, scale) in [
    (200.0, 1.0),
    (320.0, 1.0),
    (700.0, 1.0),
    (1100.0, 1.0),
    (700.0, 1.35),
    (1100.0, 2.0),
  ]) {
    testWidgets(
      'the twelve months fit a card ${width.toInt()} wide, text x$scale',
      (tester) async {
        final session = Session(
          ApiClient(client: MockClient((_) async => http.Response('{}', 404))),
        );
        await tester.pumpWidget(
          MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(scale)),
              child: child!,
            ),
            home: Scaffold(
              body: SingleChildScrollView(
                child: Center(
                  child: SizedBox(
                    width: width,
                    child: HrOverview(
                      data: {
                        'by_month': [
                          for (var i = 1; i <= 12; i++)
                            {
                              'month': '2026-${i.toString().padLeft(2, '0')}',
                              'joined': i % 4,
                              'left': i % 3,
                            },
                        ],
                      },
                      period: 'month',
                      session: session,
                    ),
                  ),
                ),
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(find.byKey(const Key('hr-by-month')), findsOneWidget);
      },
    );
  }
}
