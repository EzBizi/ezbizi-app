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
         * the second tells a branch nothing is set up for it while its company
         * has a server set up that covers it.
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

    testWidgets('the card never says "EzBizi default" about its own setting', (
      tester,
    ) async {
      /*
       * UI/UX found this in their own drawing and it IS a false friend: for
       * every other setting "EzBizi default" means A VALUE APPLIES AND IT IS
       * OURS, and for this one it would mean NO VALUE CAN APPLY AT ALL, because
       * asTenant is retired and throughTenantProvider refuses without a
       * provider. Same words, opposite operational meaning — and the misreading
       * it invites is the one Jose has corrected four times: that EzBizi sends
       * a business's reports by default.
       *
       * It is UNREACHABLE in this build rather than fixed, which is a weaker
       * guarantee than it sounds and is why this test exists: the empty state
       * draws no provenance row at all, and every path that DOES draw one has a
       * source to name. Both shapes are fed a null source_name here so that the
       * day somebody adds a provenance row to the empty state, this goes red
       * rather than the sentence quietly appearing above "Nothing is
       * connected" and contradicting it.
       */
      await _pump(tester, connectedHere: null, inEffect: _platform());

      expect(find.textContaining('EzBizi default'), findsNothing);

      await _pump(
        tester,
        connectedHere: _connected(),
        inEffect: {..._setHere(), 'source_name': null},
      );

      expect(find.textContaining('EzBizi default'), findsNothing);
    });

    testWidgets('every state carries the admission, whatever else it says', (
      tester,
    ) async {
      /*
       * REPLACES a scan for four failure sentences, which UI/UX was right to
       * call prose-aimed: it went red if somebody wrote one of those four
       * wordings and stayed green for any other wording of the same state,
       * which is the failure it was meant to catch.
       *
       * Their handle — feed a state this build has never heard of and require
       * it not to render as settled — is better, and it does not transfer: the
       * precedent they cite asserts on a PARSED MODEL (CallFlowGraph), and this
       * card has none. It reads the payload in build(). Introducing a state
       * model now, for states nothing can produce, is the speculative
       * half-build we have both refused twice.
       *
       * So this asserts the load-bearing honesty instead, and it is behavioural
       * rather than textual: whatever the card says, it says alongside "not
       * whether it worked". A future failure state cannot defeat it by being
       * worded differently — it can only defeat it by REMOVING the admission,
       * which is exactly the decision that should be hard to make by accident.
       */
      final shapes = <String, List<dynamic>>{
        // Each carries a WITNESS — text only that state renders. Without it a
        // loop of pumps proves nothing: Flutter reuses the State for the same
        // widget in the same position, so initState does not re-run and every
        // pass after the first can render the FIRST answer while the admission
        // assertion passes happily. That is the trap this file already records
        // one test above, and a loop is the shape most likely to walk into it.
        'nothing connected': [
          null,
          _platform(),
          SendingServerPanel.nothingConnected,
        ],
        'connected, untried': [
          _connected(neverTried: true),
          _setHere(),
          'Nothing has been sent',
        ],
        'connected, tried': [
          _connected(neverTried: false),
          _setHere(),
          'has been attempted',
        ],
        'inherited': [
          null,
          {..._setHere(), 'source_name': 'A-1 Chimney', 'inherited': true},
          'Inherited from A-1 Chimney',
        ],

        /*
         * A payload from a server NEWER than this client, which is reachable on
         * any deploy where the two halves ship minutes apart — not hypothetical.
         *
         * It pins forward tolerance: an outcome field this build has never heard
         * of is IGNORED, and the card goes on rendering the state it does
         * understand rather than blanking or crashing. Inventing a verdict from
         * an unknown word is the confident-wrong-cause defect; refusing to draw
         * at all is the same defect with a blank face.
         *
         * UI/UX asked for this entry to close a different hole and it does not
         * — see the note below the loop. It earns its place on this one.
         */
        'a field this build has never heard of': [
          {..._connected(neverTried: false), 'weather': 'marvellous'},
          _setHere(),
          'has been attempted',
        ],
      };

      for (final entry in shapes.entries) {
        await _pump(
          tester,
          connectedHere: entry.value[0] as Map<String, dynamic>?,
          inEffect: entry.value[1] as Map<String, dynamic>,
          key: ValueKey(entry.key),
        );

        expect(
          find.textContaining(entry.value[2] as String),
          findsOneWidget,
          reason:
              'The "${entry.key}" shape did not render — so the admission '
              'assertion below it would have been about a different state.',
        );

        expect(
          find.text(SendingServerPanel.notReportedYet),
          findsOneWidget,
          reason:
              'The "${entry.key}" state dropped the admission that this screen '
              'does not report whether sending worked.',
        );
      }

      /*
       * The fixture above said `last_result` until the admission became derived
       * from exactly that key, and the loop went red — correctly. An "unknown
       * field" fixture that happened to name the one field the card now reads is
       * a fixture asserting the opposite of what its name claims. Renamed to a
       * key nothing will ever mean.
       */
    });

    testWidgets('and the admission disappears the moment an outcome arrives', (
      tester,
    ) async {
      /*
       * THE OTHER HALF, and the reason the derivation is worth more than the
       * note it replaced. The admission is true exactly while the card reports
       * no outcome, so the two cannot drift apart by one of them being edited:
       * whoever adds the first failure state makes the sentence disappear BY
       * ADDING IT, rather than by reading a comment and remembering to delete
       * something six lines away.
       *
       * This is the assertion that makes the loop above mean something. Without
       * it, a card that ALWAYS renders the admission passes every entry, which
       * is the shape the loop had before today.
       *
       * It does depend on the field being called `last_result` — a guess, said
       * out loud in the widget. A wrong guess degrades to the old behaviour
       * rather than to something worse, which is why it is a trade worth making
       * and NOT a guarantee.
       */
      await _pump(
        tester,
        connectedHere: {..._connected(neverTried: false), 'last_result': 'refused'},
        inEffect: _setHere(),
      );

      expect(find.text(SendingServerPanel.notReportedYet), findsNothing);

      // The control: the card still drew, so this cannot pass by rendering
      // nothing at all.
      expect(find.textContaining('smtp.a-1chimney.test'), findsOneWidget);
    });

    testWidgets('the gap this still does not close', (tester) async {
      /*
       * WRITTEN DOWN RATHER THAN PAPERED OVER.
       *
       * It fires when the admission is REMOVED. It does not fire on the partial
       * change UI/UX identified and which is the likelier accident: somebody
       * adds a failure state, renders "the server refused the password", and
       * leaves the admission in — so the card contradicts itself and every
       * assertion here still passes, the unknown-state entry included. That
       * entry goes red only if the admission is dropped, which is the case
       * already covered.
       *
       * Closing it means asserting that an outcome verdict and the admission
       * are never BOTH drawn, and "is this an outcome verdict" cannot be asked
       * of rendered text without scanning prose — which is the instrument this
       * loop replaced. It needs the parsed state model we have twice agreed not
       * to build for states nothing can produce.
       *
       * So the hole is real and open. The thing that actually guards it is that
       * the admission and the failure states are the same decision: the states
       * arrive when an outcome is recorded, and that is the moment to delete
       * this sentence. Recorded here because the next person to add a failure
       * state will be reading this file.
       *
       * Deriving the admission (above) narrows it a long way: the contradiction
       * is now unrepresentable WHEN the outcome arrives under the name guessed.
       * Under a different name it is exactly the old hole, which is why this
       * note stays.
       */
      expect(SendingServerPanel.notReportedYet, isNotEmpty);
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

  /// Distinct per shape when a test pumps more than once, so Flutter builds a
  /// NEW State and initState fetches again. Without it the second pump reuses
  /// the first answer — silently, and the test reads as if it covered both.
  Key? key,
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
          child: SendingServerPanel(key: key, api: api, session: session),
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
