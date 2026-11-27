import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/business_profile/post_queue_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 176: weekly Google posts wait for Approve / Disapprove. Every name is invented.
void main() {
  List<Map<String, dynamic>> queue() => [
    {
      'id': 't1',
      'body': 'Cold nights are coming. Book your chimney sweep this month.',
      'created_at': '2026-11-09T18:00:00Z',
      'listing': {
        'id': 'l1',
        'name': 'Brightside Chimney · Hartford',
        'address': 'Hartford, CT',
        'auto_post': false,
      },
    },
    {
      'id': 't2',
      'body':
          'Is your fireplace ready for winter? A quick check now saves trouble.',
      'created_at': '2026-11-09T18:00:00Z',
      'listing': {
        'id': 'l2',
        'name': 'Harbor Flue · Stamford',
        'address': 'Stamford, CT',
        'auto_post': false,
      },
    },
  ];

  Future<List<http.Request>> pump(WidgetTester tester, Size size) async {
    final sent = <http.Request>[];
    final rows = queue();
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((request) async {
        sent.add(request);
        if (request.url.path.endsWith('/business-profile/post-queue')) {
          return http.Response(jsonEncode({'data': rows}), 200);
        }
        return http.Response(
          jsonEncode({
            'data': {'ok': true},
          }),
          200,
        );
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: PostQueueView(api: api)),
      ),
    );
    await tester.pumpAndSettle();
    return sent;
  }

  testWidgets(
    'approve sends the edited text, disapprove sends the reason, and the next post opens',
    (tester) async {
      final sent = await pump(tester, const Size(1300, 900));

      expect(
        find.text(
          'Cold nights are coming. Book your chimney sweep this month.',
        ),
        findsOneWidget,
      );
      expect(
        tester
            .widget<Switch>(find.byKey(const ValueKey('gbp-post-auto')))
            .value,
        isFalse,
      );

      await tester.tap(find.byKey(const ValueKey('gbp-post-edit')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('gbp-post-edit-field')),
        'Cold nights are coming. Book a sweep today.',
      );
      await tester.tap(find.byKey(const ValueKey('gbp-post-approve')));
      await tester.pumpAndSettle();

      final approve = sent.lastWhere((r) => r.url.path.endsWith('/t1/approve'));
      expect(jsonDecode(approve.body), {
        'body': 'Cold nights are coming. Book a sweep today.',
      });
      // Approve only queues the send; it may still fail (bug review #17).
      expect(find.textContaining('Sent to Google'), findsNothing);
      expect(
        find.textContaining('Approved. Sending to Google'),
        findsOneWidget,
      );
      expect(
        find.textContaining('Is your fireplace ready'),
        findsOneWidget,
        reason: 'the next post opens',
      );

      await tester.tap(find.byKey(const ValueKey('gbp-post-disapprove')));
      await tester.pumpAndSettle();
      expect(
        find.byType(AlertDialog),
        findsNothing,
        reason: 'the reason opens in place',
      );
      await tester.enterText(
        find.byKey(const ValueKey('gbp-post-reason')),
        'Off topic',
      );
      await tester.tap(find.byKey(const ValueKey('gbp-post-disapprove')));
      await tester.pumpAndSettle();

      final disapprove = sent.lastWhere(
        (r) => r.url.path.endsWith('/t2/disapprove'),
      );
      expect(jsonDecode(disapprove.body), {'reason': 'Off topic'});
    },
  );

  testWidgets(
    'a draft with no time asks before publishing now, and the yes is explicit',
    (tester) async {
      tester.view.physicalSize = const Size(1300, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      final approvals = <Map<String, dynamic>>[];
      final api = ApiClient(
        client: MockClient((request) async {
          if (request.url.path.endsWith('/business-profile/post-queue')) {
            return http.Response(jsonEncode({'data': queue()}), 200);
          }
          if (request.url.path.endsWith('/t1/approve')) {
            final body = request.body.isEmpty
                ? <String, dynamic>{}
                : jsonDecode(request.body) as Map<String, dynamic>;
            approvals.add(body);
            if (body['publish_now'] != true) {
              return http.Response(
                jsonEncode({
                  'code': 'needs_publish_now_confirm',
                  'message':
                      'This post has no time set — approving publishes it '
                      'now to Brightside Chimney · Hartford.',
                  'accounts': ['Brightside Chimney · Hartford'],
                }),
                422,
                // The sentence carries an em dash: say UTF-8, as the server does.
                headers: {'content-type': 'application/json; charset=utf-8'},
              );
            }
          }
          return http.Response(
            jsonEncode({
              'data': {'status': 'queued'},
            }),
            200,
          );
        }),
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(body: PostQueueView(api: api)),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('gbp-post-approve')));
      await tester.pumpAndSettle();
      expect(approvals.single.containsKey('publish_now'), isFalse);
      expect(
        find.textContaining('approving publishes it now to Brightside'),
        findsOneWidget,
      );
      expect(find.text('Publish now'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('gbp-post-approve')));
      await tester.pumpAndSettle();
      expect(approvals.last['publish_now'], isTrue);
      expect(
        find.textContaining('Approved. Sending to Google'),
        findsOneWidget,
      );
    },
  );

  testWidgets('on a phone it is one post at a time with Next listing', (
    tester,
  ) async {
    await pump(tester, const Size(390, 844));

    expect(find.text('Next listing: 1 of 2 ›'), findsOneWidget);
    await tester.ensureVisible(find.byKey(const ValueKey('gbp-posts-next')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('gbp-posts-next')));
    await tester.pumpAndSettle();
    expect(find.text('Next listing: 2 of 2 ›'), findsOneWidget);
    expect(find.textContaining('Is your fireplace ready'), findsOneWidget);
  });
}
