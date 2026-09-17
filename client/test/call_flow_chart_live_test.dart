import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_flow_chart_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The chart redraws after a change made from it (Jose, 2026-09-17).
///
/// > *"When doing a change on the Call Flow Chart, like for example i click one
/// > of the nodes and change something, the chart does not update
/// > automatically, it should be live, so whatever change is made the chart
/// > updates."*
///
/// ── Why a failed refresh is the other half of this ─────────────────
///
/// Phone's warning, and it is `queues_known` one level up: a re-read that
/// fails silently leaves a drawing that looks current and is not. Somebody who
/// has just renamed a ring group, sees the old name, and concludes the save
/// did not work is worse off than before the refresh existed — so the failure
/// is the second test rather than a line in a docblock.
void main() {
  testWidgets('closing the editor redraws the chart from the server', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    expect(find.text('Sales team'), findsOneWidget);

    await tester.tap(find.text('Sales team'));
    await tester.pumpAndSettle();

    // Somebody renames the group in the pane the drawer is hosting. The pane
    // saves to its own endpoint; all this side sees is that the graph has
    // changed underneath it.
    server.title = 'Sales and service';

    await tester.tap(find.byTooltip('Close'));
    await tester.pumpAndSettle();

    expect(
      find.text('Sales and service'),
      findsOneWidget,
      reason: 'The chart still showed the name from before the edit.',
    );
    expect(find.text('Sales team'), findsNothing);
  });

  testWidgets('a re-read that fails says so and keeps the chart', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    await tester.tap(find.text('Sales team'));
    await tester.pumpAndSettle();

    server.refuseGraph = true;

    await tester.tap(find.byTooltip('Close'));
    await tester.pumpAndSettle();

    // The chart is still there. Blanking it would lose the only picture of a
    // flow because one request failed.
    expect(find.text('Sales team'), findsOneWidget);

    // And it does not claim to be current.
    expect(
      find.textContaining('could not be re-read'),
      findsOneWidget,
      reason:
          'A chart that silently keeps the old shape reads as "your change '
          'did not save", which is the opposite of what happened.',
    );
  });

  testWidgets('a good re-read clears the warning from a bad one', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    await tester.tap(find.text('Sales team'));
    await tester.pumpAndSettle();
    server.refuseGraph = true;
    await tester.tap(find.byTooltip('Close'));
    await tester.pumpAndSettle();

    expect(find.textContaining('could not be re-read'), findsOneWidget);

    // The distinguishing case: a flag that is only ever SET reproduces the
    // failure sentence for ever, on a chart that is now perfectly current.
    server.refuseGraph = false;
    await tester.tap(find.text('Sales team'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Close'));
    await tester.pumpAndSettle();

    expect(find.textContaining('could not be re-read'), findsNothing);
  });
}

/// A server whose answer can change between two reads, which is the whole
/// subject here — a fixture that returns the same graph twice cannot tell a
/// refetch from no refetch.
class _Server {
  String title = 'Sales team';
  bool refuseGraph = false;

  http.Response call(http.Request request) {
    if (request.url.path.endsWith('/phone/call-flow/live')) {
      return _json({
        'data': {'on_line': 0, 'queues_known': false},
      });
    }

    if (request.url.path.endsWith('/phone/call-flow')) {
      if (refuseGraph) return _json({'message': 'Nope.'}, 500);

      return _json({
        'data': [
          {
            'id': 'n-1',
            'e164': '+18605550142',
            'label': 'Main line',
            'graph': {
              'nodes': [
                {
                  'id': 'caller',
                  'kind': 'caller',
                  'title': 'Caller',
                  'detail': 'Somebody dials +1 860 555 0142.',
                  'state': 'ok',
                },
                {
                  'id': 'sales',
                  'kind': 'ring_group',
                  'title': title,
                  'detail': 'Rings 3 people.',
                  'state': 'ok',
                  'edit': {'pane': 'ring-groups', 'id': 'rg-1'},
                },
              ],
              'edges': [
                {'from': 'caller', 'to': 'sales', 'label': ''},
              ],
            },
          },
        ],
      });
    }

    // Whatever the hosted settings pane asks for. It renders its own error
    // state, which is not what any of this is about.
    return _json({'message': 'Nothing stubbed for the pane.'}, 500);
  }
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Future<void> _pump(WidgetTester tester, _Server server) async {
  tester.view.physicalSize = const Size(1200, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient((r) async => server.call(r)));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      // The energy flow is a repeating AnimationController, and a repeating
      // controller schedules frames for ever — `pumpAndSettle` never returns.
      // The chart honours the reduce-motion flag for its own reasons; the
      // harness gets determinism as a side effect of that.
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(disableAnimations: true),
        child: child!,
      ),
      home: Scaffold(body: CallFlowChartPane(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}
