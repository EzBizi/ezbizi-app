import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/sending_server_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The card where a business connects its own mail server.
///
/// ── What these are FOR ───────────────────────────────────────────
///
/// Four states can exist today and three cannot, so the interesting assertions
/// are about what the card does NOT say. It must not tell a branch that nothing
/// is going out when its company is sending for it; it must not claim a send
/// worked; and it must never put the saved password on screen.
void main() {
  group('the mail server card', () {
    testWidgets('a business with nothing connected is told so, plainly', (
      tester,
    ) async {
      await _pump(tester, connectedHere: null, inEffect: _platform());

      expect(find.text(SendingServerPanel.nothingConnected), findsOneWidget);
      expect(find.text('Connect a server'), findsOneWidget);
    });

    testWidgets(
      'a branch sending through its company is NOT told nothing is going out',
      (tester) async {
        /*
         * THE distinguishing state. "No row at this scope" and "nothing is
         * sending" are different facts, and a card that rounds the first into
         * the second tells a branch its reports are not going out while its
         * company is sending them perfectly well.
         *
         * It is also the state a null check alone gets wrong: connected_here is
         * null in BOTH cases.
         */
        await _pump(
          tester,
          connectedHere: null,
          inEffect: {
            'from_address': 'info@a-1chimney.test',
            'from_name': 'A-1 Chimney',
            'transport': 'smtp',
            'transport_label': 'Your own mail server',
            'source_name': 'A-1 Chimney',
            'inherited': true,
          },
        );

        expect(find.text(SendingServerPanel.nothingConnected), findsNothing);
        expect(find.text('Inherited from A-1 Chimney'), findsOneWidget);
        expect(find.textContaining('set this business'), findsWidgets);
      },
    );

    testWidgets('a connected server shows where it sends and what it is', (
      tester,
    ) async {
      await _pump(
        tester,
        connectedHere: _connected(),
        inEffect: _setHere(),
      );

      expect(find.text('Set here'), findsOneWidget);
      expect(find.text('smtp.a-1chimney.test : 587'), findsOneWidget);
      expect(find.text('reports@a-1chimney.test'), findsOneWidget);
    });

    testWidgets('the saved password is never on the screen', (tester) async {
      /*
       * Asserted over the whole rendered tree rather than one widget, because
       * the failure guarded against is the value appearing SOMEWHERE — a debug
       * field, an echoed error, a form left populated after a save.
       *
       * The server never sends it, so this pins the client half of the same
       * promise: nothing here reconstructs or retains it.
       */
      await _pump(
        tester,
        connectedHere: _connected(),
        inEffect: _setHere(),
      );

      final texts = tester
          .widgetList<Text>(find.byType(Text))
          .map((t) => t.data ?? '')
          .join(' ');

      expect(texts, isNot(contains('the-actual-password')));

      // The control: the things that SHOULD be on screen are, so this cannot
      // pass by the card having rendered nothing at all.
      expect(texts, contains('smtp.a-1chimney.test'));
    });

    testWidgets('a server nothing has been sent through says exactly that', (
      tester,
    ) async {
      await _pump(
        tester,
        connectedHere: _connected(neverTried: true),
        inEffect: _setHere(),
      );

      expect(find.textContaining('Nothing has been sent'), findsOneWidget);
    });

    testWidgets('and it stops saying it once something has been tried', (
      tester,
    ) async {
      /*
       * The half that can distinguish. The true case alone passes against a
       * card that ALWAYS says "never tried" — which is the sentence UI/UX
       * identified as the trap: true of everything today, false the moment
       * something sends, and nothing in this file would have to change for it
       * to start lying.
       *
       * Its own test rather than a second pump in the one above: Flutter reuses
       * the State for the same widget type in the same position, so initState
       * does not run again and the second pump renders the FIRST answer. That
       * version passed for three of its four assertions and failed for a reason
       * that had nothing to do with the card.
       */
      await _pump(
        tester,
        connectedHere: _connected(neverTried: false),
        inEffect: _setHere(),
      );

      expect(find.textContaining('Nothing has been sent'), findsNothing);
      expect(find.textContaining('has been attempted'), findsOneWidget);
    });

    testWidgets('the card admits it does not report whether sending worked', (
      tester,
    ) async {
      /*
       * UI/UX's line, verbatim, and it comes out the day the failure states go
       * in. Seven settled-looking rows read as an all-clear to somebody who has
       * just typed a password and wants to know whether it took.
       *
       * Pinned in both states because the admission is most needed in the one
       * that looks most finished.
       */
      await _pump(
        tester,
        connectedHere: _connected(),
        inEffect: _setHere(),
      );
      expect(find.text(SendingServerPanel.notReportedYet), findsOneWidget);
    });

    testWidgets('and it admits it in the empty state too', (tester) async {
      // Same reason for a separate test as above, and the admission matters in
      // both: the connected state looks most finished, the empty one is where
      // somebody is about to type a password.
      await _pump(tester, connectedHere: null, inEffect: _platform());

      expect(find.text(SendingServerPanel.notReportedYet), findsOneWidget);
    });

    testWidgets('somebody who may not edit is offered no controls', (
      tester,
    ) async {
      /*
       * The server refuses them anyway — two gates, permission and reach — so
       * this is about not drawing a live control for a scope the server will
       * refuse. The settings screen learned that the hard way: computed
       * separately, the client's answer and the server's drift.
       */
      await _pump(
        tester,
        connectedHere: _connected(),
        inEffect: _setHere(),
        mayEdit: false,
      );

      expect(find.text('Change'), findsNothing);
      expect(find.text('Disconnect'), findsNothing);

      // And the facts are still readable, because being unable to change
      // something is not a reason to be unable to see it.
      expect(find.text('smtp.a-1chimney.test : 587'), findsOneWidget);
    });

    testWidgets('no failure state is drawn, because none can be produced', (
      tester,
    ) async {
      /*
       * The gallery carries refused / unreachable / not-ready. Nothing sends
       * from this screen and nothing records an outcome, so a card rendering
       * them would be showing states the system cannot reach.
       *
       * This test exists so that adding one is a DECISION rather than an
       * oversight: it goes red the day somebody draws a failure sentence, and
       * whoever does should be able to say what produces it.
       */
      await _pump(
        tester,
        connectedHere: _connected(neverTried: false),
        inEffect: _setHere(),
      );

      final texts = tester
          .widgetList<Text>(find.byType(Text))
          .map((t) => (t.data ?? '').toLowerCase())
          .join(' ');

      for (final claim in [
        'refused the user name',
        'could not reach',
        'not ready',
        'failing since',
      ]) {
        expect(
          texts,
          isNot(contains(claim)),
          reason:
              'The card drew "$claim", which nothing in the product can '
              'currently produce. If a signal now exists, say what it is.',
        );
      }
    });
  });
}

Map<String, dynamic> _connected({bool neverTried = true}) => {
  'from_address': 'reports@a-1chimney.test',
  'from_name': 'A-1 Chimney',
  'reply_to': null,
  'host': 'smtp.a-1chimney.test',
  'port': 587,
  'username': 'reports@a-1chimney.test',
  'encryption': 'tls',
  'has_password': true,
  'never_tried': neverTried,
};

Map<String, dynamic> _setHere() => {
  'from_address': 'reports@a-1chimney.test',
  'from_name': 'A-1 Chimney',
  'transport': 'smtp',
  'transport_label': 'Your own mail server',
  'source_name': 'A-1 Chimney Co',
  'inherited': false,
};

Map<String, dynamic> _platform() => {
  'from_address': 'noreply@ezbizi.test',
  'from_name': 'EzBizi',
  'transport': 'platform',
  'transport_label': 'EzBizi',
  'source_name': null,
  'inherited': false,
};

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, dynamic>? connectedHere,
  required Map<String, dynamic> inEffect,
  bool mayEdit = true,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.contains('/mail/sending-server')) {
        // Wrapped in `data`, because ApiResponse.data reads body['data'] and
        // the controller answers that way. A stub that skipped the envelope
        // would make this suite pass against a server shape the client cannot
        // read — which is exactly what happened before this line existed.
        return _json({
          'data': {
            'connected_here': connectedHere,
            'in_effect': inEffect,
            'may_edit': mayEdit,
            'may_edit_reason': mayEdit ? null : 'You do not have permission.',
            'ports': [587, 465],
          },
        });
      }

      return _json({'data': _me});
    }),
  );

  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: SendingServerPanel(api: api, session: session),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// The `/me` shape Session actually parses, taken from settings_screen_test
/// rather than invented — a stub missing `role`, `preferences`, `companies` or
/// `franchises` throws in the cast and every test in the file fails in the
/// harness rather than on an assertion.
const Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'franchise',
    'id': 'fr-1',
    'label': 'Connecticut',
    'path': ['Everdoors', 'Connecticut'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
