import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_flow_chart.dart';
import 'package:ezbizi_client/screens/phone/call_flow_chart_pane.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The call flow chart pane (Jose: desktop 1, mobile 6).
///
/// The counts are the load-bearing assertion in BOTH layouts, and they matter
/// more on the phone: the walk shows one node at a time, so until somebody has
/// walked to a dead end nothing on screen says the line is broken. The count is
/// the only thing answering "is any of this broken?" at a glance, which is why
/// it is asserted at phone width rather than only where it is easy to see.
void main() {
  testWidgets('wide: the chart draws the nodes and the wires', (tester) async {
    await _pump(tester, width: 1100);

    expect(find.text('Caller'), findsOneWidget);
    expect(find.text('Menu'), findsOneWidget);
    expect(find.text('Sales team'), findsOneWidget);

    // The wires are a painter, and nothing else in a widget test can see one.
    // Two layers (audit 2026-11-27): a still one with everything, painted
    // once, and a moving one with only the dashes, each behind its own
    // RepaintBoundary so the pulse never repaints the chart.
    bool layer(Widget w, {required bool moving}) =>
        w is CustomPaint &&
        w.painter is FlowWirePainter &&
        (w.painter! as FlowWirePainter).flowOnly == moving;
    for (final moving in [false, true]) {
      final paint = find.byWidgetPredicate((w) => layer(w, moving: moving));
      expect(paint, findsOneWidget, reason: moving ? 'moving' : 'still');
      expect(
        find.ancestor(of: paint, matching: find.byType(RepaintBoundary)),
        findsWidgets,
      );
    }
  });

  testWidgets('wide: a dead end says so on the node itself', (tester) async {
    await _pump(tester, width: 1100);

    expect(find.text('Dead end'), findsOneWidget);
    expect(
      find.text('Does nothing'),
      findsOneWidget,
      reason:
          'Not "Never reached" — the caller passes through a skipped step. '
          'The old label sent a reader hunting for a branch nobody can get '
          'to, which is a different fault in a different place.',
    );
  });

  /// The entry node is outlined in the brand colour so the eye starts where
  /// the caller does — Jose's "begins with Caller".
  ///
  /// Pinned because the styling was keyed off `kind` rather than `id` until
  /// Phone read it: two namespaces that coincide on exactly one node, so a
  /// rename on either side would have removed the outline and nothing would
  /// have gone red. A missing outline reads as a design choice.
  testWidgets('the caller is outlined differently from every other node', (
    tester,
  ) async {
    await _pump(tester, width: 1100);

    // The tile carries the treatment now, and the icon carries the tile's
    // colour -- the title sits beneath the tile rather than inside it, so
    // there is no Container ancestor of the text to read a border from.
    // .first because the fixture has three ring groups; they share one
    // treatment, so any of them answers the question.
    Color? tintOf(IconData icon) =>
        tester.widget<Icon>(find.byIcon(icon).first).color;

    final caller = tintOf(Icons.call_outlined);

    expect(caller, isNotNull);
    expect(
      tintOf(Icons.groups_outlined),
      isNot(caller),
      reason: 'An ordinary node must not borrow the entry treatment.',
    );
  });

  /// Phone's measurement of their own contract: the SMALLEST graph is two
  /// nodes, not four. A number that was imported and never pointed at EzBizi
  /// returns `caller -> arrival` and stops. Neither the gallery nor the first
  /// round of these tests had it.
  testWidgets('a line pointed nowhere is two nodes and still draws', (
    tester,
  ) async {
    await _pump(
      tester,
      width: 1100,
      graph: {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'arrival',
            'kind': 'arrival',
            'title': 'Arrival',
            'detail': 'Not pointed at EzBizi.',
            'state': 'dead',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'arrival', 'label': ''},
        ],
      },
    );

    expect(tester.takeException(), isNull);

    // Named because they are the only assertions here that DISTINGUISH this
    // graph from the default fixture: 'Caller' and '1 dead end' are both true
    // of the six-node one, so a test built on those alone would stay green if
    // the graph argument were ignored and prove nothing.
    expect(find.text('Arrival'), findsOneWidget);
    expect(find.text('Menu'), findsNothing);
    expect(find.text('1 dead end'), findsOneWidget);
  });

  /// Phone's renderer links a skipped step INTO the walk — `arrival -> menu
  /// -> answer` — so it draws between two live nodes rather than off the end.
  /// Nothing on my side covered that shape until they measured their own
  /// output and told me the description I had built to was wrong.
  testWidgets('a skipped step draws in the chain, labelled for what it is', (
    tester,
  ) async {
    await _pump(
      tester,
      width: 1100,
      graph: {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'arrival',
            'kind': 'arrival',
            'title': 'Arrival',
            'detail': 'The line picks up.',
            'state': 'ok',
          },
          {
            'id': 'menu',
            'kind': 'menu',
            'title': 'Menu',
            'detail': 'Plays nothing.',
            'state': 'skipped',
          },
          {
            'id': 'answer',
            'kind': 'ring_group',
            'title': 'Sales team',
            'detail': 'Rings 3 people.',
            'state': 'ok',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'arrival', 'label': ''},
          {'from': 'arrival', 'to': 'menu', 'label': ''},
          {'from': 'menu', 'to': 'answer', 'label': ''},
        ],
      },
    );

    expect(find.text('Menu'), findsOneWidget);
    expect(find.text('Does nothing'), findsOneWidget);
    expect(find.text('1 step does nothing'), findsOneWidget);
    expect(
      find.text('1 dead end'),
      findsNothing,
      reason:
          'Distinguishes this fixture from the default six-node one, which '
          'has a dead end; without it the test would pass on either.',
    );
  });

  testWidgets('the counts are on screen before anything is read', (
    tester,
  ) async {
    await _pump(tester, width: 1100);

    expect(find.text('1 dead end'), findsOneWidget);
    expect(find.text('1 step does nothing'), findsOneWidget);
  });

  /// The one that matters on a phone.
  ///
  /// The counts sit above the flow rather than in it, which is the whole
  /// argument for having them: a `skipped` step is the one state a picture
  /// cannot show by colouring a node, because the node works -- it is the flow
  /// THROUGH it that is empty. Phone's sentence, and the reason this assertion
  /// is at phone width rather than only where it is easy to see.
  testWidgets('narrow: the counts are on screen above the flow', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    expect(find.text('1 dead end'), findsOneWidget);
    expect(find.text('1 step does nothing'), findsOneWidget);
  });

  /// Jose picked mobile 4 over the walk that shipped, and this is the whole
  /// difference between them: the walk showed one node at a time, so anything
  /// you had not walked to was invisible.
  testWidgets('narrow: the whole flow is on one page, not one node at a time', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    expect(find.text('Caller'), findsOneWidget);
    expect(find.text('Menu'), findsWidgets);
    expect(
      find.text('Sales team'),
      findsWidgets,
      reason:
          'The walk this replaced put Sales team two taps away. If it is '
          'absent here the ribbon is rendering a path rather than the flow.',
    );
  });

  testWidgets('narrow: a branching step lists where each key goes', (
    tester,
  ) async {
    await _pump(tester, width: 400);

    expect(find.text('Press 1'), findsOneWidget);
    expect(find.text('Press 2'), findsOneWidget);
    expect(
      find.text('Service team'),
      findsWidgets,
      reason: 'A dead branch is named where the choice is made.',
    );
  });

  /// Jose, 2026-09-15, looking at the built chart: "have it begin in the
  /// middle left, so you can expand up and down when nodes branch".
  ///
  /// The old layout stacked every column from the TOP, so a five-way menu hung
  /// downwards and the spine he actually reads drifted off the bottom. Each
  /// column is now centred on the canvas midline, which is the whole of what
  /// he asked for -- so it is asserted rather than left to look right.
  testWidgets('a branch expands above AND below the caller, not downwards', (
    tester,
  ) async {
    await _pump(tester, width: 1100);

    final caller = tester.getCenter(find.byIcon(Icons.call_outlined)).dy;
    final branches = tester
        .widgetList<Icon>(find.byIcon(Icons.groups_outlined))
        .toList();

    expect(branches.length, greaterThan(1), reason: 'Needs a fan to judge.');

    final ys = find.byIcon(Icons.groups_outlined).evaluate().map((e) {
      return tester.getCenter(find.byWidget(e.widget as Icon)).dy;
    }).toList();

    expect(
      ys.any((y) => y < caller),
      isTrue,
      reason: 'Nothing sits above the caller, so the fan still hangs down.',
    );
    expect(
      ys.any((y) => y > caller),
      isTrue,
      reason: 'Nothing sits below the caller either.',
    );
  });

  /// Jose, seeing it live: "should be white on white mode, dark on dark mode".
  ///
  /// I built the canvas fixed-dark on purpose — n8n, the reference he sent, is
  /// dark in both, and a graph canvas is a drawing rather than a page. He
  /// looked at it running and ruled the other way, so the canvas follows the
  /// app like everything else.
  ///
  /// Asserted on the ground the chart paints rather than left to look right:
  /// a fixed palette and a theme-following one are indistinguishable until
  /// somebody switches theme, which no test did.
  group('the canvas follows the app theme', () {
    Color groundOf(WidgetTester tester) {
      final box = tester.widget<ColoredBox>(
        find
            .descendant(
              of: find.byType(CallFlowChartPane),
              matching: find.byType(ColoredBox),
            )
            .first,
      );

      return box.color;
    }

    testWidgets('light theme paints a light canvas', (tester) async {
      await _pump(tester, width: 1100, theme: EzBiziTheme.light());

      expect(groundOf(tester), FlowCanvas.light.ink);
    });

    testWidgets('dark theme paints a dark canvas', (tester) async {
      await _pump(tester, width: 1100, theme: EzBiziTheme.dark());

      expect(
        groundOf(tester),
        FlowCanvas.dark.ink,
        reason:
            'The control that distinguishes: if the palette were still fixed, '
            'the light test above would pass on whichever one it was fixed to '
            'and this one would fail.',
      );
    });
  });

  /// HIS ACTUAL LINE, after Phone's `5c033dda` — and the first time this
  /// widget has been asked to draw that shape.
  ///
  /// One node per group and a queue EACH means +1 239 456-0829 went from one
  /// queue to three, and from nine nodes to eleven. Every test above uses a
  /// six-node fixture with a single queue, which is the shape the layout was
  /// tuned for and no longer the shape he opens.
  ///
  /// Asserted at a real pane width rather than an infinite one: a chart that
  /// lays out correctly and overflows its box is still a chart he cannot read,
  /// and `RenderFlex overflowed` is a test failure rather than a warning here.
  testWidgets('his real line draws: eleven nodes, three queues, no overflow', (
    tester,
  ) async {
    Map<String, dynamic> node(
      String id,
      String kind,
      String title, [
      String state = 'ok',
    ]) {
      return {
        'id': id,
        'kind': kind,
        'title': title,
        'detail': 'Whoever is on duty in that group is dialled.',
        'state': state,
      };
    }

    await _pump(
      tester,
      width: 1100,
      graph: {
        'nodes': [
          node('caller', 'caller', 'Caller'),
          node('arrival', 'arrival', 'Arrives on General'),
          node('menu', 'menu', 'Menu plays'),
          node('g-appt', 'ring_group', 'Ring Appointment'),
          node('g-exist', 'ring_group', 'Ring Existing Client'),
          node('g-test', 'ring_group', 'Ring Testing'),
          node('vm-hash', 'voicemail', 'Voicemail'),
          node('queue-g-appt', 'queue', 'Hold for Appointment'),
          node('queue-g-exist', 'queue', 'Hold for Existing Client'),
          node('queue-g-test', 'queue', 'Hold for Testing'),
          node('vm-end', 'voicemail', 'Voicemail'),
        ],
        'edges': [
          {'from': 'caller', 'to': 'arrival', 'label': ''},
          {'from': 'arrival', 'to': 'menu', 'label': ''},
          {'from': 'menu', 'to': 'g-appt', 'label': 'Press 1'},
          {'from': 'menu', 'to': 'g-exist', 'label': 'Press 2'},
          {'from': 'menu', 'to': 'g-test', 'label': 'Press 3'},
          {'from': 'menu', 'to': 'vm-hash', 'label': 'Press #'},
          // The second route into one box, which is what his question was about.
          {'from': 'menu', 'to': 'g-exist', 'label': 'No key pressed'},
          {'from': 'g-appt', 'to': 'queue-g-appt', 'label': 'Everyone busy'},
          {'from': 'g-exist', 'to': 'queue-g-exist', 'label': 'Everyone busy'},
          {'from': 'g-test', 'to': 'queue-g-test', 'label': 'Everyone busy'},
          {'from': 'queue-g-appt', 'to': 'g-appt', 'label': 'try again'},
          {'from': 'queue-g-exist', 'to': 'g-exist', 'label': 'try again'},
          {'from': 'queue-g-test', 'to': 'g-test', 'label': 'try again'},
          {'from': 'queue-g-test', 'to': 'vm-end', 'label': 'Waited too long'},
        ],
      },
    );

    expect(
      tester.takeException(),
      isNull,
      reason:
          'An overflow or a layout assertion counts here: he cannot read a '
          'chart that renders past its box.',
    );

    // Distinguishes this fixture from the default six-node one, which has one
    // queue and no repeated team.
    expect(find.text('Hold for Appointment'), findsOneWidget);
    expect(find.text('Hold for Testing'), findsOneWidget);
    expect(
      find.text('Ring Existing Client'),
      findsOneWidget,
      reason:
          'Two keys reach this team and it is ONE box. Two would be the '
          'drawing Jose read as wrong.',
    );
  });

  testWidgets('his real line fits a phone too', (tester) async {
    await _pump(
      tester,
      width: 400,
      graph: {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'g-test',
            'kind': 'ring_group',
            'title': 'Ring Testing',
            'detail': 'Whoever is on duty is dialled.',
            'state': 'ok',
          },
          {
            'id': 'queue-g-test',
            'kind': 'queue',
            'title': 'Hold for Testing',
            'detail': 'Callers wait and are told their place in line.',
            'state': 'ok',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'g-test', 'label': ''},
          {'from': 'g-test', 'to': 'queue-g-test', 'label': 'Everyone busy'},
          {'from': 'queue-g-test', 'to': 'g-test', 'label': 'try again'},
        ],
      },
    );

    expect(tester.takeException(), isNull);
    expect(find.text('Hold for Testing'), findsOneWidget);
  });

  /// Live calls on the chart, chosen over editing on 2026-09-15 under Jose's
  /// standing word while he was away.
  group('live calls', () {
    Map<String, dynamic> queueGraph() => {
      'nodes': [
        {
          'id': 'caller',
          'kind': 'caller',
          'title': 'Caller',
          'detail': 'Somebody dials.',
          'state': 'ok',
        },
        {
          'id': 'g-sales',
          'kind': 'ring_group',
          'title': 'Sales team',
          'detail': 'Rings 3 people.',
          'state': 'ok',
        },
        {
          'id': 'queue-g-sales',
          'kind': 'queue',
          'title': 'Hold for Sales',
          'detail': 'Callers wait.',
          'state': 'ok',
          'ref': 'g-sales',
        },
      ],
      'edges': [
        {'from': 'caller', 'to': 'g-sales', 'label': ''},
        {'from': 'g-sales', 'to': 'queue-g-sales', 'label': 'Everyone busy'},
      ],
    };

    testWidgets('a queue holding people says so on the tile', (tester) async {
      await _pump(
        tester,
        width: 1100,
        graph: queueGraph(),
        live: {
          'on_line': 2,
          'queues': {
            'g-sales': {'waiting': 4, 'average_wait_seconds': 73},
          },
          'queues_known': true,
        },
      );

      expect(find.text('4 waiting \u00b7 1m 13s'), findsOneWidget);
      expect(find.text('2 calls on this line now'), findsOneWidget);
    });

    /// The state that must never be drawn as zero.
    testWidgets('when Twilio could not be asked, the tile says nothing', (
      tester,
    ) async {
      await _pump(
        tester,
        width: 1100,
        graph: queueGraph(),
        live: {
          'on_line': 1,
          'queues': <String, dynamic>{},
          'queues_known': false,
        },
      );

      expect(
        find.textContaining('waiting'),
        findsNothing,
        reason:
            '"0 waiting" here is a confident lie at the exact moment we '
            'have no idea. The rest of the payload is still good.',
      );
      expect(
        find.text('1 call on this line now'),
        findsOneWidget,
        reason: 'on_line does not come from Twilio, so it survives.',
      );
    });

    testWidgets('a known-empty queue reads zero rather than dashes', (
      tester,
    ) async {
      await _pump(
        tester,
        width: 1100,
        graph: queueGraph(),
        live: {
          'on_line': 0,
          'queues': <String, dynamic>{},
          'queues_known': true,
        },
      );

      expect(find.text('0 waiting'), findsOneWidget);
      expect(
        find.textContaining('on this line now'),
        findsNothing,
        reason:
            'A permanent "0 on the line" is clutter on a screen whose '
            'normal state is nobody calling.',
      );
    });

    /// The healthy line was an early return, so a flow with no faults and two
    /// callers on it would have shown neither -- the live count hidden by the
    /// state it is most worth seeing in.
    testWidgets('a healthy line shows the live count AND the all-clear', (
      tester,
    ) async {
      await _pump(
        tester,
        width: 1100,
        graph: queueGraph(),
        live: {
          'on_line': 3,
          'queues': {
            'g-sales': {'waiting': 0, 'average_wait_seconds': 0},
          },
          'queues_known': true,
        },
      );

      expect(find.text('3 calls on this line now'), findsOneWidget);
      expect(
        find.text('Every branch of this flow leads somewhere.'),
        findsOneWidget,
      );
    });

    testWidgets('the phone view carries the same count', (tester) async {
      await _pump(
        tester,
        width: 400,
        graph: queueGraph(),
        live: {
          'on_line': 0,
          'queues': {
            'g-sales': {'waiting': 2, 'average_wait_seconds': 30},
          },
          'queues_known': true,
        },
      );

      expect(find.text('2 waiting \u00b7 30s'), findsOneWidget);
    });
  });

  /// Jose, 2026-09-16: "we need to be able to zoom in - out".
  group('zoom', () {
    testWidgets('the buttons move it and the canvas says where it is', (
      tester,
    ) async {
      await _pump(tester, width: 1100);

      expect(find.text('100%'), findsOneWidget);

      await tester.tap(find.byTooltip('Zoom in'));
      await tester.pumpAndSettle();

      expect(find.text('125%'), findsOneWidget);

      await tester.tap(find.byTooltip('Zoom out'));
      await tester.pumpAndSettle();

      expect(find.text('100%'), findsOneWidget);
    });

    /// Repeated taps must not accumulate past the clamp. The scale is SET
    /// rather than multiplied into the matrix for exactly this reason.
    testWidgets('it stops at the ends instead of drifting past them', (
      tester,
    ) async {
      await _pump(tester, width: 1100);

      for (var i = 0; i < 12; i++) {
        await tester.tap(find.byTooltip('Zoom in'));
        await tester.pump();
      }
      await tester.pumpAndSettle();

      expect(
        find.text('250%'),
        findsOneWidget,
        reason: 'Twelve taps of 1.25x would reach 1455% unclamped.',
      );

      for (var i = 0; i < 20; i++) {
        await tester.tap(find.byTooltip('Zoom out'));
        await tester.pump();
      }
      await tester.pumpAndSettle();

      expect(find.text('40%'), findsOneWidget);
    });

    /// Jose also asked for the mouse wheel. InteractiveViewer scales on a
    /// trackpad pinch, and a plain wheel event is pan on some configurations
    /// -- which for somebody with a mouse is the same as having no zoom at
    /// all. Measured rather than assumed, because the two look identical in
    /// the source.
    testWidgets('the mouse wheel zooms rather than panning', (tester) async {
      await _pump(tester, width: 1100);

      final spot = tester.getCenter(find.byType(InteractiveViewer));
      final mouse = TestPointer(1, PointerDeviceKind.mouse);

      tester.binding.handlePointerEvent(mouse.hover(spot));
      tester.binding.handlePointerEvent(mouse.scroll(const Offset(0, -220)));
      await tester.pumpAndSettle();

      expect(
        find.text('100%'),
        findsNothing,
        reason:
            'A wheel that moves the view instead of scaling it leaves the '
            'zoom at 100% -- which is what no zoom looks like to a mouse.',
      );
    });

    testWidgets('tapping the number puts it back', (tester) async {
      await _pump(tester, width: 1100);

      await tester.tap(find.byTooltip('Zoom in'));
      await tester.pumpAndSettle();
      expect(find.text('100%'), findsNothing);

      await tester.tap(find.byTooltip('Reset the view'));
      await tester.pumpAndSettle();

      expect(find.text('100%'), findsOneWidget);
    });
  });

  /// Jose: "i also feel like we are missing stuff still like showing what
  /// happens when someone does take the call, and afterwards".
  ///
  /// Phone emits `answered`, `recorded` and `wrap_up` now. Before this they
  /// fell through to the unknown-kind circle — which is the right drawing for
  /// a kind nobody has heard of and the wrong one for three we have.
  testWidgets('the answered call and what follows it are drawn', (
    tester,
  ) async {
    await _pump(
      tester,
      width: 1100,
      graph: {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'g1',
            'kind': 'ring_group',
            'title': 'Sales team',
            'detail': 'Rings 3 people.',
            'state': 'ok',
          },
          {
            'id': 'answered',
            'kind': 'answered',
            'title': 'Somebody answers',
            'detail': 'The call connects.',
            'state': 'ok',
          },
          {
            'id': 'recorded',
            'kind': 'recorded',
            'title': 'Recorded',
            'detail': 'The line records and transcribes.',
            'state': 'ok',
          },
          {
            'id': 'wrap',
            'kind': 'wrap_up',
            'title': 'Saved to Records',
            'detail': 'A call tag is required.',
            'state': 'ok',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'g1', 'label': ''},
          {'from': 'g1', 'to': 'answered', 'label': 'Answered'},
          {'from': 'answered', 'to': 'recorded', 'label': ''},
          {'from': 'recorded', 'to': 'wrap', 'label': ''},
        ],
      },
    );

    expect(find.byIcon(Icons.call_end_outlined), findsOneWidget);
    expect(find.byIcon(Icons.fiber_manual_record_outlined), findsOneWidget);
    expect(find.byIcon(Icons.assignment_turned_in_outlined), findsOneWidget);
    expect(
      find.byIcon(Icons.circle_outlined),
      findsNothing,
      reason:
          'The control that distinguishes: this is the unknown-kind '
          'fallback, and finding it here means one of the three has no entry '
          'and the test above passed on the other two.',
    );
  });

  /// Jose, 2026-09-16: "it should open a right sidebar with the list of
  /// members, so i can assign - unassign, so basically, every settings needs
  /// to be SYNCED and manageable from here as well".
  ///
  /// Synced is the word that decided it. He named a property rather than a
  /// layout, and a bespoke editor per node kind is the thing that goes out of
  /// sync -- silently, the first time somebody adds a field in Settings. So
  /// the drawer opens the same pane the settings tab opens.
  group('changing a box from the chart', () {
    Map<String, dynamic> graphWith(Map<String, dynamic>? edit) => {
      'nodes': [
        {
          'id': 'caller',
          'kind': 'caller',
          'title': 'Caller',
          'detail': 'Somebody dials.',
          'state': 'ok',
        },
        {
          'id': 'g1',
          'kind': 'ring_group',
          'title': 'Ring Appointment',
          'detail': 'Whoever is on duty is dialled.',
          'state': 'ok',
          'edit': ?edit,
        },
      ],
      'edges': [
        {'from': 'caller', 'to': 'g1', 'label': ''},
      ],
    };

    testWidgets('tapping a box says which Settings pane you are in', (
      tester,
    ) async {
      await _pump(
        tester,
        width: 1100,
        graph: graphWith({'pane': 'ring-groups', 'id': 'rg-1'}),
      );

      await tester.tap(find.text('Ring Appointment'));
      await tester.pump();

      expect(
        find.text('Settings › Phone › Ring groups'),
        findsOneWidget,
        reason:
            'Somebody changing a ring group from a chart should still know '
            'they changed a ring group, and where to find it again.',
      );
    });

    /// THE ID THAT CAN TELL THE RAIL FROM A COPY OF IT.
    ///
    /// This drawer used to hold its own list of these words, and it disagreed
    /// with the rail on four ids -- it said "Blocked numbers" for a section
    /// called Blocked callers, and "Opening hours" for one called Hours. The
    /// trail exists so somebody can go and FIND the section, so naming it
    /// something the rail does not say is the one way this line can fail.
    ///
    /// The test above could never have caught it: `ring-groups` is an id where
    /// the copy happened to match, so it passes against either source. `trust`
    /// is spelled differently in the two, which is what makes it evidence.
    testWidgets('the trail says what the rail says, not what we remember', (
      tester,
    ) async {
      await _pump(
        tester,
        width: 1100,
        graph: graphWith({'pane': 'trust', 'id': 't-1'}),
      );

      await tester.tap(find.text('Ring Appointment'));
      await tester.pump();

      expect(
        find.text('Settings › Phone › Trust & caller identity'),
        findsOneWidget,
        reason:
            'A local list of labels would say "Trust" here, and pass every '
            'other assertion in this file.',
      );
    });

    testWidgets('a step that is not a setting says so instead', (tester) async {
      await _pump(tester, width: 1100, graph: graphWith(null));

      await tester.tap(find.text('Ring Appointment'));
      await tester.pump();

      expect(
        find.textContaining('nothing to change on it here'),
        findsOneWidget,
      );
      expect(
        find.textContaining('Settings › Phone ›'),
        findsNothing,
        reason:
            'A breadcrumb to a pane that does not edit this box would send '
            'somebody looking for a setting that is not there.',
      );
    });

    /// The failure this file is most likely to have: a pane id it has never
    /// heard of. It must degrade to a door, never to another pane's content,
    /// because wrong content looks like it worked.
    testWidgets('a pane this build does not know degrades to a door', (
      tester,
    ) async {
      await _pump(
        tester,
        width: 1100,
        graph: graphWith({'pane': 'something-phone-added-later', 'id': 'x'}),
      );

      await tester.tap(find.text('Ring Appointment'));
      await tester.pump();

      expect(
        find.textContaining('changed in Settings › Phone.'),
        findsOneWidget,
        reason:
            'The trail STOPS rather than naming a section. Falling back '
            'to the raw id would render "Settings › Phone › cost" for a '
            'section called Cost, sending somebody hunting a lowercase one.',
      );
      expect(
        find.textContaining('something-phone-added-later'),
        findsNothing,
        reason: 'An internal id is not a place a person can go looking.',
      );
    });

    testWidgets('closing it puts the chart back', (tester) async {
      await _pump(
        tester,
        width: 1100,
        graph: graphWith({'pane': 'ring-groups', 'id': 'rg-1'}),
      );

      await tester.tap(find.text('Ring Appointment'));
      await tester.pump();
      expect(find.byTooltip('Close'), findsOneWidget);

      await tester.tap(find.byTooltip('Close'));
      await tester.pump();

      expect(find.textContaining('Settings › Phone ›'), findsNothing);
    });

    /// Containment (Jose: "u broke the containment rule"): on a phone the
    /// editor used to be a screen PUSHED over the module. It draws in the
    /// chart's own place now, so it is a descendant of the chart pane, and its
    /// back arrow puts the flow back.
    testWidgets(
      'on a phone the editor takes the flow’s place, and back returns '
      'to it',
      (tester) async {
        await _pump(
          tester,
          width: 390,
          graph: graphWith({'pane': 'ring-groups', 'id': 'rg-1'}),
        );

        await tester.tap(find.text('Ring Appointment'));
        await tester.pumpAndSettle();

        expect(
          find.descendant(
            of: find.byType(CallFlowChartPane),
            matching: find.text('Settings › Phone › Ring groups'),
          ),
          findsOneWidget,
          reason: 'The editor is not inside the chart pane: it was pushed.',
        );

        await tester.tap(find.byTooltip('Back to the call flow'));
        await tester.pumpAndSettle();

        expect(find.textContaining('Settings › Phone ›'), findsNothing);
        expect(find.text('Ring Appointment'), findsOneWidget);
      },
    );
  });

  _pickerGroup();

  /// A painter is not repainted for free; a stale one draws yesterday's wires
  /// under today's boxes.
  group('the wires repaint when the flow changes', () {
    FlowWirePainter painter(CallFlowGraph g) => FlowWirePainter(
      layout: layOutFlow(g),
      colour: const Color(0xFF000000),
      labelColour: const Color(0xFF000000),
    );

    final one = CallFlowGraph(
      nodes: const [
        FlowNode(
          id: 'caller',
          kind: 'caller',
          title: 'C',
          detail: '',
          state: 'ok',
        ),
        FlowNode(id: 'b', kind: 'b', title: 'B', detail: '', state: 'ok'),
      ],
      edges: const [FlowEdge(from: 'caller', to: 'b')],
      entry: phoneEntryNode,
    );

    test('a different flow repaints', () {
      final two = CallFlowGraph(
        nodes: [...one.nodes],
        edges: const [],
        entry: phoneEntryNode,
      );

      expect(painter(one).shouldRepaint(painter(two)), isTrue);
    });

    test('and the same painter does not', () {
      final p = painter(one);

      expect(p.shouldRepaint(p), isFalse);
    });
  });
}

/// The number picker (Jose: picker 8, 2026-09-15 — "we also want a search bar
/// for the number picker").
///
/// The search field is the ask; the GROUPING is what makes it worth building
/// rather than bolting a field onto the dropdown. Thirteen lines and the
/// question is usually "which one is broken", so a line with a fault is the
/// first row before anything is typed.
///
/// These counts need no server work, which I got wrong when I first put this
/// option to Jose: I told him it was blocked on Phone sending a per-line
/// summary. `/phone/call-flow` already answers with every line and its whole
/// graph. I had read the brief rather than the payload.
void _pickerGroup() {
  List<Map<String, dynamic>> twoLines() => [
    {
      'id': 'ok-1',
      'e164': '+18605550100',
      'label': 'Accounts',
      'graph': {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'vm',
            'kind': 'voicemail',
            'title': 'Voicemail',
            'detail': 'Takes a message.',
            'state': 'ok',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'vm', 'label': ''},
        ],
      },
    },
    {
      'id': 'bad-1',
      'e164': '+18605550199',
      'label': 'Service',
      'graph': {
        'nodes': [
          {
            'id': 'caller',
            'kind': 'caller',
            'title': 'Caller',
            'detail': 'Somebody dials.',
            'state': 'ok',
          },
          {
            'id': 'nobody',
            'kind': 'ring_group',
            'title': 'Service team',
            'detail': 'Nobody is in this group.',
            'state': 'dead',
          },
        ],
        'edges': [
          {'from': 'caller', 'to': 'nobody', 'label': ''},
        ],
      },
    },
  ];

  testWidgets('the picker leads with the line that has a fault', (
    tester,
  ) async {
    await _pump(tester, width: 1100, lines: twoLines());

    await tester.tap(find.byType(TextButton).first);
    await tester.pumpAndSettle();

    expect(find.text('NEEDS ATTENTION — 1'), findsOneWidget);
    expect(find.text('1 dead end'), findsWidgets);

    // The order is the feature, not the grouping header. Accounts is healthy
    // and must sit below Service however the list is built.
    // Scoped to the dialog: the button BEHIND it also shows a line name, so
    // an unscoped finder reads the header and answers about the wrong widget.
    Finder inDialog(String text) => find.descendant(
      of: find.byType(AlertDialog),
      matching: find.textContaining(text),
    );

    final faulty = tester.getTopLeft(inDialog('Service').first).dy;
    final healthy = tester.getTopLeft(inDialog('Accounts').first).dy;

    expect(
      faulty,
      lessThan(healthy),
      reason:
          'A healthy line above a broken one is the dropdown this replaced.',
    );
  });

  testWidgets('the picker searches digits, punctuation and all', (
    tester,
  ) async {
    await _pump(tester, width: 1100, lines: twoLines());

    await tester.tap(find.byType(TextButton).first);
    await tester.pumpAndSettle();

    // Nobody types the punctuation, and half the time you know the number
    // rather than the name.
    await tester.enterText(find.byType(TextField), '0199');
    await tester.pumpAndSettle();

    Finder inDialog(String text) => find.descendant(
      of: find.byType(AlertDialog),
      matching: find.textContaining(text),
    );

    expect(inDialog('Service'), findsWidgets);
    expect(
      inDialog('Accounts'),
      findsNothing,
      reason: 'A filter that keeps the non-matching row is not a filter.',
    );
  });

  testWidgets('a line with no faults is not given a needs-attention group', (
    tester,
  ) async {
    await _pump(tester, width: 1100, lines: [twoLines().first]);

    await tester.tap(find.byType(TextButton).first);
    await tester.pumpAndSettle();

    expect(
      find.textContaining('NEEDS ATTENTION'),
      findsNothing,
      reason:
          'The control that distinguishes: if the group were unconditional '
          'the test above would pass on a build that always draws it.',
    );
  });
}

// ── harness ────────────────────────────────────────────────────────

/// One line, shaped like `TwimlRenderer::graph()` output: a menu whose second
/// option is dead, a queue that returns to the group it came from, and a
/// greeting no caller reaches.
Map<String, dynamic> _graph() => {
  'nodes': [
    {
      'id': 'caller',
      'kind': 'caller',
      'title': 'Caller',
      'detail': 'Somebody dials +1 860 555 0142.',
      'state': 'ok',
    },
    {
      'id': 'menu',
      'kind': 'menu',
      'title': 'Menu',
      'detail': 'Press 1 or 2.',
      'state': 'ok',
    },
    {
      'id': 'sales',
      'kind': 'ring_group',
      'title': 'Sales team',
      'detail': 'Rings 3 people.',
      'state': 'ok',
    },
    {
      'id': 'service',
      'kind': 'ring_group',
      'title': 'Service team',
      'detail': 'Nobody is in this group',
      'state': 'dead',
    },
    {
      'id': 'queue',
      'kind': 'queue',
      'title': 'Queue',
      'detail': 'Holds and tries again.',
      'state': 'ok',
    },
    {
      'id': 'greeting',
      'kind': 'menu',
      'title': 'Greeting',
      'detail': 'A recorded welcome.',
      'state': 'skipped',
    },
  ],
  'edges': [
    {'from': 'caller', 'to': 'menu', 'label': ''},
    {'from': 'menu', 'to': 'sales', 'label': 'Press 1'},
    {'from': 'menu', 'to': 'service', 'label': 'Press 2'},
    {'from': 'sales', 'to': 'queue', 'label': 'Everyone busy'},
    {'from': 'queue', 'to': 'sales', 'label': 'try again'},
  ],
};

Future<void> _pump(
  WidgetTester tester, {
  required double width,
  Map<String, dynamic>? graph,
  List<Map<String, dynamic>>? lines,
  ThemeData? theme,
  Map<String, dynamic>? live,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/phone/call-flow/live')) {
        return http.Response(
          jsonEncode({
            'data': live ?? {'on_line': 0, 'queues_known': false},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/phone/call-flow')) {
        return http.Response(
          jsonEncode({
            'data':
                lines ??
                [
                  {
                    'id': 'n-1',
                    'e164': '+18605550142',
                    'label': 'Main line',
                    'graph': graph ?? _graph(),
                  },
                ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        '{}',
        500,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  // The drawer hosts the real settings panes and they take a session, so the
  // pane does too. One pane, one session, one editor -- which is the whole
  // reason the drawer hosts them rather than drawing its own.
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: theme ?? EzBiziTheme.light(),
      // REDUCE MOTION, and not only for tidiness. The energy flow Jose asked
      // for is an AnimationController on repeat, and a repeating controller
      // schedules frames forever -- `pumpAndSettle` never returns and every
      // test here times out rather than failing on anything real. The chart
      // honours the flag because somebody with vestibular trouble should not
      // be given a pulsing canvas either; the harness gets determinism as a
      // side effect of the accessibility behaviour rather than a switch of
      // its own.
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(disableAnimations: true),
        child: child!,
      ),
      home: Scaffold(
        body: CallFlowChartPane(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
