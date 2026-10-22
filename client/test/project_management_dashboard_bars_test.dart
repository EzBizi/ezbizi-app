import 'package:ezbizi_client/screens/jobs/pm_stages.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Jose, 2026-10-22: "bars dont have any collors? ... and the middle one is
/// endless". The overview here has the live shape measured that day: every
/// job waiting review has no reason, "Unassigned" dwarfs every team, and
/// hundreds of paid jobs wait to start. Every name invented.
void main() {
  const none = {'value': null, 'label': 'No reason'};
  Map<String, dynamic> overview() => {
    ...pmOverview(),
    'waiting_review': {
      'count': 90,
      'by_reason': [
        {'value': 'order_parts', 'label': 'Order parts', 'count': 0},
        {...none, 'count': 90},
      ],
      'by_location': [
        for (var i = 0; i < 20; i++)
          {
            'name': 'Place $i',
            'count': 20 - i,
            'by_reason': [
              {'value': 'order_parts', 'label': 'Order parts', 'count': 0},
              {...none, 'count': 20 - i},
            ],
          },
      ],
    },
    'per_team': [
      {'name': 'Unassigned', 'count': 1334},
      for (var i = 0; i < 20; i++) {'name': 'Team $i', 'count': 26 - i},
    ],
    'waiting_to_start': [
      for (var i = 0; i < 40; i++)
        {
          'job': {
            'id': 'j-$i',
            'number': 'JOB-$i',
            'customer': 'Customer With A Long Name $i',
          },
          'paid_at': '2026-01-01',
          'days': 400 - i,
          'limit': 10,
        },
    ],
  };

  /// The painted width and colour of a bar's first filled segment.
  (double, Color) filled(WidgetTester tester, Finder bar) {
    final boxes = find
        .descendant(of: bar, matching: find.byType(ColoredBox))
        .evaluate()
        .toList();
    // [0] is the track; [1] the first segment.
    expect(boxes.length, greaterThan(1), reason: 'the bar has no segment');
    final seg = boxes[1];
    return (
      tester.getSize(find.byWidget(seg.widget)).width,
      (seg.widget as ColoredBox).color,
    );
  }

  testWidgets('every card\'s bars are filled and coloured, not bare track', (
    tester,
  ) async {
    await PmServer(
      overview: overview(),
    ).pump(tester, size: const Size(1440, 1600));
    final track = (find.byType(PmBar).evaluate().first.widget as PmBar);
    expect(track, isNotNull);

    for (final bar in [
      find
          .descendant(
            of: find.byKey(const ValueKey('pm-review-loc-Place 5')),
            matching: find.byType(PmBar),
          )
          .first,
      find.byKey(const ValueKey('pm-team-bar-Team 5')),
      find
          .descendant(
            of: find.byKey(const ValueKey('pm-start-bar-j-3')),
            matching: find.byType(PmBar),
          )
          .first,
    ]) {
      final (width, colour) = filled(tester, bar);
      expect(width, greaterThan(20));
      expect(colour.a, greaterThan(0));
      // Not the neutral family the track is drawn in.
      expect(colour, isNot(const Color(0xffe8ecf0)));
      final trackColour =
          (find
                      .descendant(of: bar, matching: find.byType(ColoredBox))
                      .evaluate()
                      .first
                      .widget
                  as ColoredBox)
              .color;
      expect(colour, isNot(trackColour));
    }

    // Teams scale against the busiest team, not Unassigned; Unassigned last.
    final (team0, _) = filled(
      tester,
      find.byKey(const ValueKey('pm-team-bar-Team 0')),
    );
    final (team5, _) = filled(
      tester,
      find.byKey(const ValueKey('pm-team-bar-Team 5')),
    );
    expect(team5 / team0, closeTo(21 / 26, 0.02));
  });

  testWidgets('the cards show their first rows, then the rest on demand', (
    tester,
  ) async {
    await PmServer(
      overview: overview(),
    ).pump(tester, size: const Size(1440, 1600));

    expect(find.byKey(const ValueKey('pm-start-j-9')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-start-j-10')), findsNothing);
    expect(
      find.text('and 30 more over the 10-day limit · See all'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('pm-team-Team 11')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-team-Team 12')), findsNothing);
    expect(find.byKey(const ValueKey('pm-review-loc-Place 12')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('pm-start-more')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-start-j-39')), findsOneWidget);

    await tester.ensureVisible(find.byKey(const ValueKey('pm-teams-more')));
    await tester.tap(find.byKey(const ValueKey('pm-teams-more')));
    await tester.pumpAndSettle();
    // Unassigned is last.
    final ys = [
      tester.getTopLeft(find.byKey(const ValueKey('pm-team-Team 19'))).dy,
      tester.getTopLeft(find.byKey(const ValueKey('pm-team-Unassigned'))).dy,
    ];
    expect(ys[1], greaterThan(ys[0]));
    expect(tester.takeException(), isNull);
  });

  testWidgets('a waiting job\'s full name is on hover', (tester) async {
    await PmServer(
      overview: overview(),
    ).pump(tester, size: const Size(1440, 1600));
    final tip = tester.widget<Tooltip>(
      find.byKey(const ValueKey('pm-start-name-j-0')),
    );
    expect(tip.message, 'JOB-0 · Customer With A Long Name 0');
  });
}
