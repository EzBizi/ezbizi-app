import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_flow_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Clicking a step goes to the screen that changes it.
///
/// Jose, 2026-09-23: *"i'd like to be able to click the 4 items they each have,
/// and that should direct me to the screen where that can be changed."*
///
/// The destination is the SERVER's — `TwimlRenderer::describe()` stamps each
/// step with the same `{pane, id}` the call flow chart puts on its nodes — so
/// what this file pins is that the screen carries it faithfully and does not
/// invent one.
void main() {
  testWidgets('a step opens the section the server named', (tester) async {
    final opened = <String>[];
    await _pump(tester, onOpen: opened.add);

    await tester.tap(find.byKey(const Key('call-flow-step:answer')));
    await tester.pumpAndSettle();

    expect(opened, ['ring-groups']);

    await tester.tap(find.byKey(const Key('call-flow-step:menu')));
    await tester.pumpAndSettle();

    // The SECOND one matters as much as the first: a screen that sent every
    // step to one place would pass on either tap alone.
    expect(opened, ['ring-groups', 'flows']);
  });

  /// A step the server gave no destination stays a plain row.
  ///
  /// A button that does nothing is the failure this whole screen exists to
  /// prevent — it is the screen for finding out that something configured is
  /// doing nothing.
  testWidgets('a step with no destination is not a button', (tester) async {
    final opened = <String>[];
    await _pump(tester, onOpen: opened.add);

    expect(find.byKey(const Key('call-flow-step:fallback')), findsNothing);

    await tester.tap(find.text('Falls back to +13055550111'));
    await tester.pumpAndSettle();

    expect(opened, isEmpty);
  });

  /// And with nowhere to send anybody, nothing is tappable at all.
  ///
  /// The control for the case above: without it, "not a button" passes on a
  /// build where no step is ever a button.
  testWidgets('every stamped step is tappable when the tab can navigate', (
    tester,
  ) async {
    final opened = <String>[];
    await _pump(tester, onOpen: opened.add);

    for (final step in ['arrival', 'hours', 'menu', 'answer']) {
      expect(
        find.byKey(Key('call-flow-step:$step')),
        findsOneWidget,
        reason: '$step carries a destination and should be tappable',
      );
    }
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required void Function(String) onOpen,
}) async {
  tester.view.physicalSize = const Size(900, 1200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/phone/call-flow')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'n-1',
                'e164': '+12394560829',
                'label': 'General',
                'record': true,
                'transcribe': true,
                'steps': [
                  {
                    'step': 'arrival',
                    'title': 'A call arrives on +12394560829',
                    'detail': 'Twilio posts it to EzBizi.',
                    'usable': true,
                    'edit': {'pane': 'numbers', 'id': 'n-1'},
                  },
                  {
                    'step': 'hours',
                    'title': 'Business hours: always open',
                    'detail': 'No schedule is set.',
                    'usable': true,
                    'edit': {'pane': 'hours', 'id': null},
                  },
                  {
                    'step': 'menu',
                    'title': 'IVR menu plays',
                    'detail': 'The caller hears it.',
                    'usable': true,
                    'edit': {'pane': 'flows', 'id': 'f-1'},
                  },
                  {
                    'step': 'answer',
                    'title': 'Rings Existing Client',
                    'detail': 'Whoever is on duty is dialled.',
                    'usable': true,
                    'edit': {'pane': 'ring-groups', 'id': 'g-1'},
                  },
                  {
                    // No `edit` at all — an older server, or a step nothing
                    // can change. The row must survive it.
                    'step': 'fallback',
                    'title': 'Falls back to +13055550111',
                    'detail': 'Tried once, then voicemail.',
                    'usable': true,
                  },
                ],
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response('{"message":"unexpected"}', 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CallFlowPane(api: api, onOpen: onOpen),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
