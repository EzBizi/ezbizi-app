import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Approved colour layout 3 / 6 (docs/previews/leads-conversation-colour-
/// layouts.html, c31b50ad): our bubbles are `primary` with `onPrimary` text,
/// theirs `surface` with an `outlineVariant` border.
///
/// Checked in BOTH themes, because `primary` inverts between them: light is a
/// dark teal carrying white text, dark is a bright cyan carrying near-black
/// text. A bubble that looks right in one screenshot is no evidence about the
/// other, so this reads the tokens the widget actually painted with.
///
/// Scope of the "no leading-edge bar" check, stated so it is not trusted for
/// more than it holds: it compares the LEFT and RIGHT edges only - a leading
/// edge is one of those two by definition - and pins the left to
/// `outlineVariant` so two symmetric saturated bars could not pass. Top and
/// bottom are not compared.
void main() {
  // Jose, 2026-11-16: an automatic text says which rule wrote it.
  testWidgets('an automatic message names its rule', (tester) async {
    await _pump(tester, EzBiziTheme.light());
    expect(find.textContaining('Automatic · Not booked - after a call'), findsOneWidget);
  });

  for (final (name, theme) in [
    ('light', EzBiziTheme.light()),
    ('dark', EzBiziTheme.dark()),
  ]) {
    testWidgets('$name: our bubble is primary with onPrimary text, theirs is '
        'surface with an outline, and no leading-edge bar anywhere', (
      tester,
    ) async {
      await _pump(tester, theme);

      final scheme = theme.colorScheme;

      final ours = _bubbleAround(tester, 'Yes, 10am works.');
      expect(ours.color, scheme.primary);
      expect(ours.border, isNull);
      expect(_textColour(tester, 'Yes, 10am works.'), scheme.onPrimary);

      final theirs = _bubbleAround(tester, 'Is Saturday ok?');
      expect(theirs.color, scheme.surface);
      expect(theirs.border, isNotNull);
      expect(
        (theirs.border as Border).left.color,
        scheme.outlineVariant,
        reason: 'an outline all round, never a saturated bar down one edge',
      );
      expect((theirs.border as Border).left, (theirs.border as Border).right);

      // Sent outside EzBizi (no sender here, not automatic): red, with its
      // own ink (Jose, 2026-09-30).
      final outside = _bubbleAround(tester, 'Called you back from the office.');
      expect(outside.color, scheme.error);
      expect(
        _textColour(tester, 'Called you back from the office.'),
        scheme.onError,
      );
      expect(find.textContaining('Sent outside EzBizi'), findsOneWidget);

      // An automation's reply: green, the ok tone's fill and ink (Jose,
      // 2026-09-30: "instead of blue like the other, maybe green").
      final rule = ToneColours.of(
        tester.element(find.text('Thanks! A dispatcher will call shortly.')),
        Tone.ok,
      );
      final automatic = _bubbleAround(
        tester,
        'Thanks! A dispatcher will call shortly.',
      );
      expect(automatic.color, rule.fill);
      expect(automatic.color, isNot(scheme.primary));
      expect(
        _textColour(tester, 'Thanks! A dispatcher will call shortly.'),
        rule.ink,
      );
    });
  }
}

BoxDecoration _bubbleAround(WidgetTester tester, String text) {
  final container = find
      .ancestor(of: find.text(text), matching: find.byType(Container))
      .first;

  return tester.widget<Container>(container).decoration! as BoxDecoration;
}

// A message is SelectableText since 2026-09-29 (copy and paste); the ink is
// on its style either way.
Color? _textColour(WidgetTester tester, String text) {
  final widget = tester.widget(find.text(text));
  return switch (widget) {
    EditableText() => widget.style.color,
    SelectableText() => widget.style?.color,
    _ => (widget as Text).style?.color,
  };
}

Future<void> _pump(WidgetTester tester, ThemeData theme) async {
  tester.view.physicalSize = const Size(600, 900);
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
                {
                  'id': 'c-in',
                  'channel': 'MESSAGE',
                  'from': 'CONSUMER',
                  'occurred_at': '2026-09-07T09:00:00Z',
                  'text': 'Is Saturday ok?',
                  'attachments': <String>[],
                  'has_recording': false,
                },
                {
                  'id': 'c-out',
                  'channel': 'MESSAGE',
                  'from': 'ADVERTISER',
                  'occurred_at': '2026-09-07T09:30:00Z',
                  'text': 'Yes, 10am works.',
                  'attachments': <String>[],
                  'has_recording': false,
                  // Sent from EzBizi, so it names who.
                  'sent_by': 'Andy Ocampo',
                },
                {
                  'id': 'c-outside',
                  'channel': 'MESSAGE',
                  'from': 'ADVERTISER',
                  'occurred_at': '2026-09-07T09:40:00Z',
                  'text': 'Called you back from the office.',
                  'attachments': <String>[],
                  'has_recording': false,
                },
                {
                  'id': 'c-rule',
                  'channel': 'MESSAGE',
                  'from': 'ADVERTISER',
                  'occurred_at': '2026-09-07T09:05:00Z',
                  'text': 'Thanks! A dispatcher will call shortly.',
                  'attachments': <String>[],
                  'has_recording': false,
                  'automatic': true,
                  'automation_name': 'Not booked - after a call',
                },
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
      theme: theme,
      home: Scaffold(
        body: LeadThread(
          api: api,
          lead: const {
            'id': 'lead-1',
            'contact_name': 'Marcus Bell',
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
