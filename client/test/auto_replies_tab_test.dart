import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customer_experience/auto_replies_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose 2026-11-10: the review and our reply side by side; a draft can be published. Names invented.
void main() {
  testWidgets('shows the review, our reply and the counts; a draft publishes', (
    tester,
  ) async {
    final sent = <http.Request>[];
    tester.view.physicalSize = const Size(1200, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((request) async {
        sent.add(request);
        if (request.url.path.endsWith('/reviews/auto-replies')) {
          return http.Response(
            jsonEncode({
              'data': [
                {
                  'id': 'r1',
                  'reviewer_name': 'Dana Whit',
                  'rating': 2,
                  'comment': 'Late again.',
                  'reviewed_at': '2026-11-09T15:00:00Z',
                  'franchise': {'id': 'f1', 'name': 'North'},
                  'reply': {
                    'body': 'Sorry Dana, we will call you.',
                    'status': 'draft',
                  },
                },
              ],
              'meta': {
                'counts': {'published': 120, 'draft': 60, 'failed': 0},
                'drafts_note': 'Why drafts wait.',
              },
            }),
            200,
          );
        }
        return http.Response(jsonEncode({'data': {}}), 200);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: AutoRepliesTab(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Published · 120'), findsOneWidget);
    expect(find.text('Waiting · 60'), findsOneWidget);
    expect(find.text('Late again.'), findsOneWidget);
    expect(find.text('Sorry Dana, we will call you.'), findsOneWidget);
    expect(find.text('Why drafts wait.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('auto-reply-publish')));
    await tester.pumpAndSettle();
    expect(
      sent.any(
        (r) =>
            r.method == 'POST' &&
            r.url.path.endsWith('/reviews/r1/reply/publish'),
      ),
      isTrue,
    );
  });
}
