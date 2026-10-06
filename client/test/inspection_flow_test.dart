import 'package:ezbizi_client/screens/inspection/inspection_flow.dart';
import 'package:ezbizi_client/widgets/flow_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The inspection flow chart (Jose, 2026-09-17: the call flow chart, in the
/// Inspection Builder).
///
/// The picture is Phone's and is tested where it lives. What is tested here is
/// the only part that is Inspection's: **which screens a technician can
/// actually reach**, which the Builder's list and its phone preview both show
/// identically whether the answer is "every time" or "never".
void main() {
  group('which screens can be reached', () {
    test('a condition on a LATER question can never be true', () {
      final graph = inspectionFlow(_definition)!;

      // `visible_if` is evaluated against the answers given so far, so `eq`
      // against a question two screens further on is false every single time.
      // This is the whole reason the chart earns a place in the Builder: in
      // the list beside it, this screen looks exactly like any other.
      expect(graph.node('screen-4')!.isDead, isTrue);
      expect(graph.node('screen-4')!.detail, contains('Never shown'));
      expect(graph.deadCount, 1);
    });

    test('the same condition on an EARLIER question is ordinary', () {
      final graph = inspectionFlow(_definition)!;

      // The pair that can distinguish: screens 1 and 4 carry the SAME operator
      // on the same shape of value, and differ only in whether the question
      // has been asked yet. A reachability test that ignored the ordering
      // would call them both live, or both dead, and pass neither way.
      expect(graph.node('screen-1')!.isDead, isFalse);
      expect(graph.node('screen-1')!.state, 'ok');
    });

    test('a screen with no questions is passed straight through', () {
      final graph = inspectionFlow(_definition)!;

      expect(graph.node('screen-3')!.isSkipped, isTrue);
      expect(graph.skippedCount, 1);
    });

    test('an unanswerable NOT is always true, which is not a dead screen', () {
      final graph = inspectionFlow({
        'name': 'Backwards',
        'screens': [
          {
            'title': 'Only sometimes',
            'visible_if': {'op': 'ne', 'field': 'later', 'value': 'yes'},
            'fields': [
              {'key': 'a', 'label': 'A'},
            ],
          },
          {
            'title': 'Later',
            'fields': [
              {'key': 'later', 'label': 'Later question'},
            ],
          },
        ],
      })!;

      // The mirror of the dead case, and the one an "is it reachable" check
      // gets wrong by symmetry: nothing is broken, the screen shows every
      // time, and its author wrote a condition that does nothing.
      expect(graph.node('screen-0')!.state, 'ok');
      expect(graph.deadCount, 0);

      final wire = graph.edges.firstWhere((e) => e.to == 'screen-0');
      expect(wire.label, contains('Always'));
    });
  });

  group('the shape of the walk', () {
    test('a conditional screen is a branch, not a step in the line', () {
      final graph = inspectionFlow(_definition)!;
      final out = graph.out('screen-0').map((e) => e.to).toList();

      // From the screen before an optional one there are genuinely two ways
      // on, and drawing only the first would say the optional screen is
      // compulsory.
      expect(out, contains('screen-1'));
      expect(out, contains('screen-2'));

      // And the optional screen rejoins rather than ending the inspection.
      expect(graph.out('screen-1').map((e) => e.to), contains('screen-2'));
    });

    test('the branch wire says what opens it, in the author"s own words', () {
      final graph = inspectionFlow(_definition)!;
      final wire = graph.edges.firstWhere(
        (e) => e.from == 'screen-0' && e.to == 'screen-1',
      );

      // The field's LABEL, not its key: the key is an identifier the author
      // may never have chosen.
      expect(wire.label, 'Roof condition is failed');
    });

    test('the walk is laid out from the start box, not from a caller', () {
      final layout = layOutFlow(inspectionFlow(_definition)!);

      // The distinguishing case for [CallFlowGraph.entry]. A layout still
      // seeded on Phone's `caller` finds no such node, seeds nothing, and
      // drops every box into one column -- a tidy picture of nothing, with no
      // error anywhere.
      expect(layout.find(inspectionEntry)!.column, 0);
      expect(layout.find('screen-0')!.column, 1);
      expect(layout.columns, greaterThan(2));
    });

    test('a screen is called by its name, the key the Builder and the server '
        'write', () {
      // `name` is what the Builder's Add screen, the A1 port and the run view
      // all use. The chart read `title`, which nothing writes, so every real
      // screen was drawn as "Screen 1" to "Screen 4".
      final graph = inspectionFlow({
        'name': 'Chimney',
        'screens': [
          {
            'name': 'Client and site',
            'fields': [
              {'key': 'a', 'label': 'A'},
            ],
          },
          {'fields': <Map<String, dynamic>>[]},
        ],
      })!;

      expect(graph.node('screen-0')!.title, 'Client and site');
      expect(graph.node('screen-1')!.title, 'Screen 2');
    });

    test('an inspection with no screens has no flow to draw', () {
      expect(inspectionFlow(null), isNull);
      expect(inspectionFlow({'name': 'Empty', 'screens': []}), isNull);
    });
  });

  group('on screen', () {
    testWidgets('the chart names the screens and counts the faults', (
      tester,
    ) async {
      var opened = -1;

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SizedBox(
              width: 1000,
              height: 700,
              child: InspectionFlowPane(
                definition: _definition,
                onOpenScreen: (i) => opened = i,
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Roof'), findsOneWidget);
      expect(find.text('1 screen can never be shown'), findsOneWidget);
      expect(find.text('1 screen asks nothing'), findsOneWidget);

      await tester.tap(find.text('Notes'));
      await tester.pump();

      // Tapping a box selects that screen in the Builder, so the chart is a
      // way to get to a screen rather than a second place to read about one.
      expect(opened, 2);
    });

    testWidgets('a definition with nothing in it says so', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: InspectionFlowPane(
              definition: null,
              onOpenScreen: (_) {},
            ),
          ),
        ),
      );

      expect(find.textContaining('Choose an inspection'), findsOneWidget);
    });
  });
}

/// One definition carrying every case at once, because they interact: the
/// dead screen has to stay dead with a live one two boxes away.
final Map<String, dynamic> _definition = {
  'name': 'Chimney inspection',
  'screens': [
    {
      'title': 'Roof',
      'fields': [
        {'key': 'roof', 'label': 'Roof condition', 'type': 'choice'},
      ],
    },
    {
      'title': 'Roof detail',
      'visible_if': {'op': 'eq', 'field': 'roof', 'value': 'failed'},
      'fields': [
        {'key': 'why', 'label': 'What failed'},
      ],
    },
    {
      'title': 'Notes',
      'fields': [
        {'key': 'notes', 'label': 'Notes'},
      ],
    },
    {'title': 'Nothing here', 'fields': <Map<String, dynamic>>[]},
    {
      'title': 'Backwards',
      'visible_if': {'op': 'eq', 'field': 'later', 'value': 'yes'},
      'fields': [
        {'key': 'x', 'label': 'X'},
      ],
    },
    {
      'title': 'Later',
      'fields': [
        {'key': 'later', 'label': 'Later question'},
      ],
    },
  ],
};
