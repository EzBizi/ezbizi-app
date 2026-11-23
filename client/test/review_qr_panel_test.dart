import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customer_experience/review_qr_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Review QR (design 204, Jose 2026-11-23). Every name invented.
void main() {
  final posted = <Map<String, dynamic>>[];
  var refuse = false;

  setUp(() {
    posted.clear();
    refuse = false;
  });

  Future<void> pump(
    WidgetTester tester, {
    String? jobId,
    double width = 1200,
  }) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    const h = {'content-type': 'application/json'};
    final api = ApiClient(
      client: MockClient((r) async {
        final p = r.url.path;
        if (p.endsWith('/customer-experience/review-qr')) {
          posted.add(jsonDecode(r.body) as Map<String, dynamic>);
          if (refuse) {
            return http.Response(
              '{"message":"No branch serves that ZIP code yet."}',
              422,
              headers: h,
            );
          }
          return http.Response(
            jsonEncode({
              'data': {
                'url': 'https://example.test/r/ab12cd',
                'code': 'ab12cd',
                'expires_at': '2027-11-23T12:00:00Z',
                'branch': {'id': 'f1', 'name': 'Akron'},
              },
            }),
            200,
            headers: h,
          );
        }
        if (p.endsWith('/customers')) {
          return http.Response(
            '{"data":[{"id":"c1","name":"Dana Reyes","city":"Akron"}]}',
            200,
            headers: h,
          );
        }
        if (p.endsWith('/jobs')) {
          return http.Response(
            '{"data":[{"id":"j1","number":"JOB-04926","customer":{"name":"Dana Reyes"}}]}',
            200,
            headers: h,
          );
        }
        return http.Response('{}', 404, headers: h);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReviewQrPanel(api: api, jobId: jobId),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> type(WidgetTester tester, String text) async {
    await tester.enterText(find.byType(TextField), text);
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
  }

  testWidgets('five digits is a ZIP; Generate shows the code and branch', (
    tester,
  ) async {
    await pump(tester);
    expect(
      tester
          .widget<FilledButton>(
            find.byKey(const ValueKey('review-qr-generate')),
          )
          .onPressed,
      isNull,
      reason: 'nothing picked yet',
    );

    await type(tester, '44310');
    await tester.tap(find.byKey(const ValueKey('review-qr-match-zip-44310')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('review-qr-generate')));
    await tester.pumpAndSettle();

    expect(posted.single, {'zip': '44310'});
    expect(find.byKey(const ValueKey('review-qr-image')), findsOneWidget);
    expect(find.text('Akron'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('review-qr-new')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('review-qr-form')), findsOneWidget);
  });

  testWidgets('a name finds clients and jobs; a job sends its id', (
    tester,
  ) async {
    await pump(tester, width: 390);
    await type(tester, 'dana');

    expect(find.text('Dana Reyes'), findsWidgets);
    await tester.tap(find.byKey(const ValueKey('review-qr-match-job-j1')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('review-qr-generate')));
    await tester.pumpAndSettle();

    expect(posted.single, {'job_id': 'j1'});
    expect(find.byKey(const ValueKey('review-qr-image')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets("the server's refusal is said, the form stays", (tester) async {
    refuse = true;
    await pump(tester);
    await type(tester, '99999');
    await tester.tap(find.byKey(const ValueKey('review-qr-match-zip-99999')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('review-qr-generate')));
    await tester.pumpAndSettle();

    expect(find.text('No branch serves that ZIP code yet.'), findsOneWidget);
    expect(find.byKey(const ValueKey('review-qr-image')), findsNothing);
  });

  testWidgets('given a job, the code is made straight away', (tester) async {
    await pump(tester, jobId: 'j7');
    expect(posted.single, {'job_id': 'j7'});
    expect(find.byKey(const ValueKey('review-qr-image')), findsOneWidget);
  });
}
