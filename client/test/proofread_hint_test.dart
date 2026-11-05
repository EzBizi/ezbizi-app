import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/widgets/proofread_hint.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Phone's texts get Leads' "Did you mean" (Jose, 2026-11-05).
void main() {
  testWidgets('an answer for words since changed is dropped', (tester) async {
    final asked = <String>[];
    final api = ApiClient(
      client: MockClient((r) async {
        asked.add(r.url.path);
        return http.Response(
          jsonEncode({
            'data': {'suggestion': "Yes, I'll call you right away"},
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    final box = TextEditingController();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              TextField(controller: box),
              ProofreadHint(api: api, controller: box, path: '/proofread'),
            ],
          ),
        ),
      ),
    );

    box.text = 'Yes, I call you right away';
    await tester.pump(const Duration(seconds: 1));
    box.text = 'Yes, I call you right away!';
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reply-proofread')), findsNothing);

    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
    expect(asked.last, '/api/v1/proofread');
    expect(find.byKey(const ValueKey('reply-proofread')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('reply-proofread-dismiss')));
    await tester.pump();
    expect(find.byKey(const ValueKey('reply-proofread')), findsNothing);
    expect(
      box.text,
      'Yes, I call you right away!',
      reason: 'never changed by itself',
    );
  });
}
