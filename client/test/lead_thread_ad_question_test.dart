import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Approved design 158, option 1 + 4 (docs/previews/leads-ad-question-
/// layouts.html): a question tapped on a Meta ad is tagged, outlined, and its
/// meta line says "tapped on your ad". False or missing `ad_question` is a
/// plain customer bubble.
void main() {
  for (final (name, width) in [('desktop', 900.0), ('phone', 390.0)]) {
    testWidgets('$name: the tag shows only for ad_question true', (
      tester,
    ) async {
      await _pump(tester, width);

      expect(find.byKey(const ValueKey('ad-question-c-ad')), findsOneWidget);
      expect(find.text('AD QUESTION (META)'), findsOneWidget);
      expect(find.byKey(const ValueKey('ad-question-c-false')), findsNothing);
      expect(find.byKey(const ValueKey('ad-question-c-missing')), findsNothing);
      expect(find.textContaining('tapped on your ad'), findsOneWidget);

      final scheme = EzBiziTheme.light().colorScheme;
      final tag = tester.widget<Text>(find.text('AD QUESTION (META)'));
      expect(tag.style?.color, scheme.tertiary);
      expect(tag.style?.fontWeight, FontWeight.bold);

      // No fill: the ad bubble is painted, not a filled Container.
      expect(
        find.ancestor(
          of: find.text('Can I get a quote?'),
          matching: find.byKey(const ValueKey('ad-question-bubble-c-ad')),
        ),
        findsOneWidget,
      );
      final plain = find
          .ancestor(
            of: find.text('Typed it myself'),
            matching: find.byType(Container),
          )
          .first;
      expect(
        (tester.widget<Container>(plain).decoration! as BoxDecoration).color,
        scheme.surface,
      );
    });
  }
}

const _none = Object();

Map<String, dynamic> _msg(String id, String text, {Object? ad = _none}) => {
  'id': id,
  'channel': 'MESSAGE',
  'from': 'CONSUMER',
  'occurred_at': '2026-09-07T09:00:00Z',
  'text': text,
  'attachments': <String>[],
  'has_recording': false,
  if (ad != _none) 'ad_question': ad,
};

Future<void> _pump(WidgetTester tester, double width) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/leads/lead-1')) {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'lead-1',
              'conversations': [
                _msg('c-ad', 'Can I get a quote?', ad: true),
                _msg('c-false', 'Typed it myself', ad: false),
                _msg('c-missing', 'No flag at all'),
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response(
        '{}',
        404,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadThread(
          api: api,
          lead: const {
            'id': 'lead-1',
            'contact_name': 'Dana Reyes',
            'phone': '+1 (555) 123-4567',
            'service': 'fireplace_chimney',
            'status': 'new',
          },
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
