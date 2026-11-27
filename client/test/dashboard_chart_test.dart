import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:ezbizi_client/widgets/dashboard_chart.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Charts sit on the glance when the server sent slices or series; empty and
/// coming-soon stay a sentence. The switcher is a header control, not a
/// dashboard-wide graph mode, and coming soon has none.
void main() {
  testWidgets('a pie is drawn for slices and not for coming soon', (tester) async {
    await _pumpDashboard(tester, _glances(presentation: 'pie', slices: [
      {'label': 'scheduled', 'total': 2},
      {'label': 'completed', 'total': 1},
    ]));
    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('coming soon has no chart and no presentation control', (tester) async {
    await _pumpDashboard(tester, _comingSoon());
    expect(find.byType(DashboardChart), findsNothing);
    expect(find.byTooltip('Choose panels'), findsNothing);
    // Muse 10.5: a coming-soon box is not drawn at all.
    expect(find.text('Coming soon'), findsNothing);
  });

  testWidgets('empty is a sentence, not a chart', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        empty: true,
        slices: [
          {'label': 'scheduled', 'total': 0},
        ],
        sentence: 'No jobs on the board.',
      ),
    );

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('No jobs on the board.'), findsOneWidget);
  });

  testWidgets('text presentation draws sentences only', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'text',
        slices: [
          {'label': 'scheduled', 'total': 2},
          {'label': 'completed', 'total': 1},
        ],
      ),
    );

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('2 jobs on the board.'), findsOneWidget);
  });

  testWidgets('the chart sits above the sentences', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
          {'label': 'completed', 'total': 1},
        ],
        sentence: '3 jobs on the board.',
      ),
    );

    expect(
      tester.getTopLeft(find.byType(DashboardChart)).dy,
      lessThan(tester.getTopLeft(find.text('3 jobs on the board.')).dy),
    );
  });

  testWidgets('a bar is drawn for slices', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'bar',
        allowed: const ['bar', 'pie', 'text'],
        slices: [
          {'label': 'Onboardings in flight', 'total': 2},
          {'label': 'Invitations stalled', 'total': 1},
        ],
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('a year series of Y-m buckets still draws', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'line',
        allowed: const ['line', 'bar', 'text'],
        series: [
          {
            'label': 'Added',
            'points': [
              {'at': '2026-08', 'value': 2},
              {'at': '2026-07', 'value': 1},
            ],
          },
        ],
        sentence: '3 new customers this year.',
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('pie and bar name their slices', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
          {'label': 'completed', 'total': 1},
        ],
      ),
    );

    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('scheduled'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('completed'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a line is drawn for series', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'line',
        allowed: const ['line', 'bar', 'text'],
        series: [
          {
            'label': 'Added',
            'points': [
              {'at': '2026-08-01', 'value': 0},
              {'at': '2026-08-08', 'value': 2},
            ],
          },
        ],
        sentence: '2 new customers this month.',
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  testWidgets('a line names every currency series', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: DashboardChart(
            presentation: 'line',
            series: [
              {
                'label': 'CAD',
                'points': [
                  {'at': '2026-08-01', 'value': 5000},
                  {'at': '2026-08-08', 'value': 7000},
                ],
              },
              {
                'label': 'USD',
                'points': [
                  {'at': '2026-08-01', 'value': 10000},
                  {'at': '2026-08-08', 'value': 20000},
                ],
              },
            ],
          ),
        ),
      ),
    );

    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('CAD'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(DashboardChart),
        matching: find.text('USD'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a timeline is drawn for series', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'timeline',
        allowed: const ['timeline', 'text'],
        series: [
          {
            'label': 'Posted',
            'points': [
              {'at': '2026-08-01T10:00:00-04:00', 'value': 1},
              {'at': '2026-08-12T14:00:00-04:00', 'value': 1},
            ],
          },
        ],
        sentence: '2 posts this month.',
      ),
    );

    expect(find.byType(DashboardChart), findsOneWidget);
  });

  /// "Configured but empty" — a glance with real categories and a zero in
  /// every one. Scheduling asked whether the dashboard has a convention for
  /// it; it does, and this pins it so the answer stays true.
  ///
  /// No chart at all, and every sentence kept.
  ///
  /// This was asserted as a measured `Size.zero` when it was written, with a
  /// note explaining that `findsNothing` was the wrong claim: the screen's
  /// own `_chartable` said "the list is non-empty, draw it" while the widget
  /// said "nothing is above zero, paint nothing", so a `DashboardChart` sat
  /// in the tree at zero height. That contradiction is gone — both sides now
  /// go through one definition, and the strict one won — so the honest
  /// assertion is the one a reader would have written first.
  ///
  /// The trap this also pins: `empty: true` would have been the tempting way
  /// to say it, and the empty branch draws exactly ONE sentence
  /// (`_sentenceOf` takes `sentence`, or `sentences.first`). A glance whose
  /// sentences are ordered so the actionable one comes first would silently
  /// lose the rest. All-zero slices with `empty: false` is the shape that
  /// keeps them.
  testWidgets('every slice at zero draws no chart and keeps every sentence', (
    tester,
  ) async {
    await _pumpDashboard(tester, [
      {
        'key': 'jobs',
        'label': 'Jobs',
        'presentation': 'bar',
        'allowed_presentations': ['bar', 'text'],
        'snapshot': true,
        'coming_soon': false,
        'options': <String, dynamic>{},
        'data': {
          'empty': false,
          'slices': [
            {'label': 'Mon', 'total': 0},
            {'label': 'Tue', 'total': 0},
            {'label': 'Wed', 'total': 0},
          ],
          'sentences': [
            'Two areas have no hours set.',
            'These are configured, not booked.',
          ],
        },
      },
    ]);

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.text('Two areas have no hours set.'), findsOneWidget);
    expect(find.text('These are configured, not booked.'), findsOneWidget);
  });

  /// The control for the test above: the SAME shape with one non-zero slice
  /// does draw. Without it, a change that stopped rendering charts entirely
  /// would leave that `findsNothing` green and reading like a feature.
  testWidgets('one non-zero slice is enough to draw the chart', (tester) async {
    await _pumpDashboard(tester, [
      {
        'key': 'jobs',
        'label': 'Jobs',
        'presentation': 'bar',
        'allowed_presentations': ['bar', 'text'],
        'snapshot': true,
        'coming_soon': false,
        'options': <String, dynamic>{},
        'data': {
          'empty': false,
          'slices': [
            {'label': 'Mon', 'total': 0},
            {'label': 'Tue', 'total': 3},
            {'label': 'Wed', 'total': 0},
          ],
          'sentences': ['Two areas have no hours set.'],
        },
      },
    ]);

    expect(find.byType(DashboardChart), findsOneWidget);
    expect(find.text('Two areas have no hours set.'), findsOneWidget);
  });

  /// This test used to assert the bug, and was named for it.
  ///
  /// It read "empty draws the first sentence and drops the rest", and it was
  /// true: `_body` short-circuited to a single `DashboardNote`, so an empty
  /// box with three sentences showed one and threw two away. I wrote it as
  /// documentation of a trap and told two sessions to avoid `empty: true`.
  ///
  /// Email then found it in production. Two mailboxes had been broken since
  /// 2026-08-20; broken means no slice, both broken means no slices at all,
  /// so `empty` went true and the home screen named one dead inbox and
  /// silently dropped the other, for three weeks.
  ///
  /// **A documented trap is still a trap, and a test that pins one defends
  /// it.** Anybody removing the short-circuit would have found this red and
  /// read it as "the behaviour is deliberate, somebody tested it". Fixed at
  /// the root instead; this now asserts what a reader would have assumed all
  /// along.
  testWidgets('empty draws every sentence, not just the first', (
    tester,
  ) async {
    await _pumpDashboard(tester, [
      {
        'key': 'jobs',
        'label': 'Jobs',
        'presentation': 'bar',
        'allowed_presentations': ['bar', 'text'],
        'snapshot': true,
        'coming_soon': false,
        'options': <String, dynamic>{},
        'data': {
          'empty': true,
          'sentences': [
            'Two areas have no hours set.',
            'These are configured, not booked.',
          ],
        },
      },
    ]);

    expect(find.text('Two areas have no hours set.'), findsOneWidget);
    expect(find.text('These are configured, not booked.'), findsOneWidget);
  });

  /// What `empty: true` actually suppresses, pinned because it is now written
  /// in `GlancePayload`'s docblock as the rule for glance authors.
  ///
  /// It is a STATEMENT rather than a READING: no chart and no money lines,
  /// whatever the payload carries, and every sentence in the muted note
  /// style. The trap used to be that it dropped all but the first sentence;
  /// that is gone, and the consequence to watch is the opposite one — a box
  /// with something to plot must not set it, or the chart silently will not
  /// appear.
  ///
  /// Asserted with a payload that carries BOTH a drawable slice and a money
  /// list, so the suppression is the thing under test rather than an absence
  /// that would have happened anyway.
  testWidgets('empty suppresses the chart and the money, not the words', (
    tester,
  ) async {
    await _pumpDashboard(tester, [
      {
        'key': 'jobs',
        'label': 'Jobs',
        'presentation': 'bar',
        'allowed_presentations': ['bar', 'text'],
        'snapshot': true,
        'coming_soon': false,
        'options': <String, dynamic>{},
        'data': {
          'empty': true,
          'slices': [
            {'label': 'Done', 'total': 7},
          ],
          'revenue': [
            {'currency': 'CAD', 'amount_minor': 9900},
          ],
          'sentences': ['Two inboxes could not be asked.', 'One is ours.'],
        },
      },
    ]);

    expect(find.byType(DashboardChart), findsNothing);
    expect(find.textContaining('CAD'), findsNothing);
    expect(find.text('Two inboxes could not be asked.'), findsOneWidget);
    expect(find.text('One is ours.'), findsOneWidget);
  });

  /// THE DEFECT JOSE NAMED, and it took two slices to show.
  ///
  /// The palette was six `ColorScheme` roles indexed `i % 6`. `theme.dart`
  /// pins `primary` and `secondary` to the same deep cyan on light, on
  /// purpose — so slices one and two drew IDENTICALLY on every light-theme
  /// pie, at the default four job statuses. Not an edge case at seven; a
  /// live defect at two.
  testWidgets('two slices are two different colours', (tester) async {
    final dots = await _legendColors(tester, const [
      {'label': 'Scheduled', 'total': 3},
      {'label': 'In progress', 'total': 2},
    ]);

    expect(dots, hasLength(2));
    expect(dots[0], isNot(dots[1]));
  });

  /// Fixed order, never cycled — and the tail is a neutral, not a ninth hue.
  ///
  /// `jobs.statuses` is a per-organization setting with no cap, so nine is
  /// reachable. The old code drew slice nine in slice one's colour, which
  /// reads as a correct chart: there is no ellipsis for a repeated colour.
  testWidgets('nine slices never repeat a hue', (tester) async {
    final dots = await _legendColors(tester, [
      for (var i = 0; i < 9; i++) {'label': 'S$i', 'total': i + 1},
    ]);

    expect(dots, hasLength(9));

    // The eight identities are eight distinct colours.
    expect(dots.take(8).toSet(), hasLength(8));

    // The ninth is the neutral tail, and emphatically not slot one's blue.
    expect(dots[8], isNot(dots[0]));
  });

  /// NO TWO LEGEND ROWS EVER SHARE A COLOUR — including at the tail.
  ///
  /// The first version of the fix gave every slot past the eighth the SAME
  /// neutral, so ten slices drew two identically-coloured dots against two
  /// different labels: the original defect reproduced at the far end of its
  /// own fix. UI/UX found it by reading the code, not the summary.
  ///
  /// A grey wedge can honestly say "not an identity". A legend ROW cannot —
  /// it puts a swatch beside a name, which promises you can find that name in
  /// the picture, and three grey rows cannot keep that promise. So the tail is
  /// one row now, named for its count.
  testWidgets('twelve slices still draw no two rows alike', (tester) async {
    final dots = await _legendColors(tester, [
      for (var i = 0; i < 12; i++) {'label': 'S$i', 'total': i + 1},
    ]);

    expect(dots, hasLength(9), reason: 'Eight identities and one tail.');
    expect(
      dots.toSet(),
      hasLength(9),
      reason: 'Two rows share a colour — the defect this change removes.',
    );
  });

  /// The tail says what it is, and the numbers still add up.
  ///
  /// Folding is only honest if nothing goes missing: twelve slices totalling
  /// 78 must still total 78 once four of them are one row. The label carries
  /// the count so "running out" is something the chart SAYS rather than
  /// something a reader has to notice.
  testWidgets('the folded tail is named for its count', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: DashboardChart(
            presentation: 'pie',
            slices: [
              for (var i = 0; i < 12; i++) {'label': 'S$i', 'total': i + 1},
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('4 more'), findsOneWidget);
    expect(find.text('S8'), findsNothing, reason: 'S8 is inside the tail.');
    expect(find.text('S7'), findsOneWidget, reason: 'S7 is the eighth identity.');

    expect(_chartSemantics(tester), contains('S11 12'));
  });

  /// THE PICTURE FOLDS; THE READING DOES NOT.
  ///
  /// The fold exists because two wedges cannot share a colour and two legend
  /// rows cannot share a swatch. A screen reader has neither problem — it
  /// reads a list, and a list has no adjacency and no colour. So folding what
  /// it hears would solve a drawing problem by deleting data from the one
  /// channel that was never broken.
  ///
  /// The first version of the fold did exactly that: `_foldTail` ran before
  /// the painter was built and `semanticsLabel` iterated the painter's
  /// slices, so at twelve categories a screen reader heard "… Quoted 6, 4 more
  /// 42" where it used to hear all twelve names. UI/UX found it on the third
  /// pass, and the docblock still claimed semantics "names every slice with
  /// its total" — false, in the same file that carries the sentence about a
  /// control describing what you are looking at.
  ///
  /// This is the assertion that would have caught it, and nothing else here
  /// would: **a category the legend has folded away is still spoken by name.**
  testWidgets('the screen reader hears every category the picture folds', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: DashboardChart(
            presentation: 'pie',
            slices: [
              for (var i = 0; i < 12; i++) {'label': 'S$i', 'total': i + 1},
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final spoken = _chartSemantics(tester);

    // Folded out of the picture entirely — no wedge, no legend row.
    expect(find.text('S8'), findsNothing);
    expect(find.text('S11'), findsNothing);
    expect(find.text('4 more'), findsOneWidget);

    // And every one of them still named, with its own total, to a reader.
    for (var i = 0; i < 12; i++) {
      expect(
        spoken,
        contains('S$i ${i + 1}'),
        reason: 'S$i vanished from the non-visual channel.',
      );
    }

    // The tail label is a DRAWING device and has no business being spoken as
    // though it were a category: the reader got the twelve real ones instead.
    expect(spoken, isNot(contains('4 more')));
  });

  /// Dark is its own set of steps, not an automatic flip of the light one.
  testWidgets('dark mode draws its own palette', (tester) async {
    final light = await _legendColors(
      tester,
      const [
        {'label': 'A', 'total': 1},
      ],
    );
    final dark = await _legendColors(
      tester,
      const [
        {'label': 'A', 'total': 1},
      ],
      dark: true,
    );

    expect(light.single, isNot(dark.single));
  });

  /// The tail cannot switch the legend on by itself.
  ///
  /// Whether there IS a legend is a question about the data; what it SAYS is a
  /// question about the drawing. `"12 more"` is a label this widget
  /// manufactures, so asking the folded list "is anything named here" lets a
  /// chart with no real names grow a legend whose only row maps nothing to
  /// nothing — where before the fold it drew none.
  ///
  /// Raised by UI/UX as a shape, explicitly without establishing whether a
  /// glance can send unlabelled slices. Measured afterwards: it cannot — every
  /// glance builds labels from a status slug, a definition name or a date
  /// bucket. Pinned anyway, because the coupling is one line and the same
  /// family as everything else this file has been wrong about.
  testWidgets('unlabelled slices draw no legend, tail or not', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: DashboardChart(
            presentation: 'pie',
            slices: [
              for (var i = 0; i < 12; i++) {'label': '', 'total': i + 1},
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // The chart itself still draws — there are totals to plot.
    expect(find.byType(DashboardChart), findsOneWidget);

    // But nothing is named, so there is nothing to legend.
    expect(find.text('4 more'), findsNothing);
  });

  /// Jose, 2026-09-14: hovering a fraction of the pie should highlight it and
  /// show the percentage.
  ///
  /// Four equal slices, so the arithmetic is unambiguous and a wrong total or
  /// a wrong wedge both show up as a wrong number rather than as a near miss.
  /// Wedges start at twelve o'clock and run clockwise, so the first quarter is
  /// the top-right and a point up-and-right of centre lands in it.
  testWidgets('hovering a wedge shows its share', (tester) async {
    await _pumpPie(tester, const [
      {'label': 'Scheduled', 'total': 1},
      {'label': 'In progress', 'total': 1},
      {'label': 'Completed', 'total': 1},
      {'label': 'Cancelled', 'total': 1},
    ]);

    expect(find.textContaining('%'), findsNothing, reason: 'Not hovering yet.');

    final hover = await _pointerOver(tester);

    await hover(const Offset(38, -38));
    expect(find.text('25% · Scheduled'), findsOneWidget);

    // The wedge a quarter turn on is a different one, and says so.
    await hover(const Offset(38, 38));
    expect(find.text('25% · In progress'), findsOneWidget);
    expect(find.text('25% · Scheduled'), findsNothing);
  });

  /// The share is the slice's, not a quarter by luck.
  testWidgets('an uneven pie reports each wedge honestly', (tester) async {
    await _pumpPie(tester, const [
      {'label': 'Big', 'total': 75},
      {'label': 'Small', 'total': 25},
    ]);

    final hover = await _pointerOver(tester);

    // Three quarters of the circle, so the top-right is inside Big.
    await hover(const Offset(38, -38));
    expect(find.text('75% · Big'), findsOneWidget);

    // Small occupies 270 degrees to 360, which is the arc from nine o'clock
    // back up to twelve — the TOP-left. Bottom-left is 225 degrees and still
    // inside Big; I had this wrong and the test said so, which is the whole
    // reason the fixture is 75/25 rather than something that could be near.
    await hover(const Offset(-38, -38));
    expect(find.text('25% · Small'), findsOneWidget);
  });

  /// Off the ring is not a wedge — the hole and the corners are not hits.
  testWidgets('the hole and the corners show nothing', (tester) async {
    await _pumpPie(tester, const [
      {'label': 'Scheduled', 'total': 1},
      {'label': 'Completed', 'total': 1},
    ]);

    final hover = await _pointerOver(tester);

    await hover(const Offset(38, -38));
    expect(find.textContaining('%'), findsOneWidget, reason: 'A control.');

    await hover(Offset.zero);
    expect(find.textContaining('%'), findsNothing, reason: 'The donut hole.');
  });

  /// A wedge too small to round up says so rather than claiming to be absent.
  testWidgets('a sliver reads as less than one percent', (tester) async {
    await _pumpPie(tester, const [
      {'label': 'Bulk', 'total': 1000},
      {'label': 'Sliver', 'total': 1},
    ]);

    expect(_chartSemantics(tester), contains('Sliver 1 (<1%)'));
  });

  /// THE HALF OF THE FEATURE THAT IS DRAWN, NOT WRITTEN.
  ///
  /// Found by sabotaging my own work after committing it: making the lift a
  /// no-op, and making `shouldRepaint` blind to the highlight, each failed
  /// ZERO tests — while a wrong percentage failed three. The tooltip is a
  /// widget, so `find.text` catches it; the highlight is pixels, and nothing
  /// was looking. Jose asked for two things and only one was guarded.
  ///
  /// These two are what a canvas can be held to without reading pixels: the
  /// hovered wedge is drawn WIDER than the others, and the painter reports it
  /// needs repainting when the hover moves. Miss the second and the state
  /// updates while the screen does not.
  test('the hovered wedge is drawn wider than the rest', () {
    expect(
      DashboardChart.liftFactor,
      greaterThan(1.0),
      reason: 'At 1.0 the hover is invisible and everything else still works.',
    );
  });

  testWidgets('moving the pointer asks the canvas to repaint', (tester) async {
    await _pumpPie(tester, const [
      {'label': 'Scheduled', 'total': 1},
      {'label': 'Completed', 'total': 1},
    ]);

    final before = _painterOf(tester);

    final hover = await _pointerOver(tester);
    await hover(const Offset(38, -38));

    final after = _painterOf(tester);

    expect(
      identical(before, after),
      isFalse,
      reason: 'A hover that never rebuilt the painter cannot have repainted.',
    );
    expect(
      after.shouldRepaint(before),
      isTrue,
      reason: 'The highlight changed and the canvas was not told.',
    );
  });

  /// TOUCH GETS THE SAME ANSWER, added after hover shipped alone.
  ///
  /// `MouseRegion` never fires on a phone, so the share was a number only a
  /// mouse could see. A tap is safe to claim here because the card's reorder
  /// drag is bound to its GRIP rather than to the whole card — checked before
  /// adding it, not after.
  ///
  /// Tapping the open wedge closes it: touch has no `onExit`, so without that
  /// the tooltip is a thing you can open and not put away.
  testWidgets('tapping a wedge shows its share, and again closes it', (
    tester,
  ) async {
    await _pumpPie(tester, const [
      {'label': 'Scheduled', 'total': 1},
      {'label': 'Completed', 'total': 1},
    ]);

    final paint = find
        .descendant(
          of: find.byType(DashboardChart),
          matching: find.byType(CustomPaint),
        )
        .first;
    final centre = tester.getCenter(paint);

    await tester.tapAt(centre + const Offset(38, -38));
    await tester.pumpAndSettle();
    expect(find.text('50% · Scheduled'), findsOneWidget);

    await tester.tapAt(centre + const Offset(38, -38));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('%'),
      findsNothing,
      reason: 'A second tap on the same wedge must put it away.',
    );
  });

  /// HOVER ENHANCES, IT DOES NOT GATE.
  ///
  /// `MouseRegion` never fires on a touch screen, so on a phone this layer
  /// does nothing. That is only acceptable while every number the pointer can
  /// reveal is reachable without one — so the shares are spoken too.
  testWidgets('every share is reachable without a pointer', (tester) async {
    await _pumpPie(tester, const [
      {'label': 'Scheduled', 'total': 3},
      {'label': 'Completed', 'total': 1},
    ]);

    final spoken = _chartSemantics(tester);
    expect(spoken, contains('Scheduled 3 (75%)'));
    expect(spoken, contains('Completed 1 (25%)'));
  });

  testWidgets('coming soon has no how-to-draw menu', (tester) async {
    await _pumpDashboard(tester, _comingSoon());

    expect(find.byType(PopupMenuButton<String>), findsNothing);
    expect(find.byTooltip('How to draw “Inventory”'), findsNothing);
  });

  testWidgets('an empty allowed list hides the presentation menu', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'text',
        allowed: const [],
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
    );

    expect(find.byType(PopupMenuButton<String>), findsNothing);
  });

  /// `web_management` and `business_profile` are sentence-only glances: their
  /// allowed list is exactly `['text']`. A menu whose single entry is already
  /// selected is a control that cannot change anything, which is the same
  /// argument the empty list is hidden for.
  testWidgets('one allowed presentation hides the menu too', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'text',
        allowed: const ['text'],
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
    );

    expect(find.byType(PopupMenuButton<String>), findsNothing);
    expect(find.byTooltip('How to draw “Jobs”'), findsNothing);
  });

  /// The screen used to add `text` to any list that omitted it. The server
  /// does not accept it back: `declaredPresentation` drops a kind the glance
  /// never offered to the default, which
  /// `DashboardTest::test_a_presentation_not_offered_is_dropped_to_the_default`
  /// pins. So the entry drew text on this frame and the next load put the
  /// chart back — a control that looked like it worked. The allowed list is
  /// the server's answer; offer exactly it.
  testWidgets('only what the server allows is offered', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        allowed: const ['pie', 'bar'],
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
    );

    await tester.tap(find.byTooltip('How to draw “Jobs”'));
    await tester.pumpAndSettle();

    expect(find.text('Pie'), findsOneWidget);
    expect(find.text('Bar'), findsOneWidget);
    expect(find.text('Text'), findsNothing);
  });

  testWidgets('choosing a presentation writes it with the current glances', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
      record: sent,
    );

    await tester.tap(find.byTooltip('How to draw “Jobs”'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bar'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.single.containsKey('period'), isFalse);
    expect((sent.single['glances'] as List).single['key'], 'jobs');
    expect((sent.single['glances'] as List).single['presentation'], 'bar');
  });

  testWidgets('the presentation menu sits in the card header', (tester) async {
    await _pumpDashboard(
      tester,
      _glances(
        presentation: 'pie',
        slices: [
          {'label': 'scheduled', 'total': 2},
        ],
      ),
    );

    final titleY = tester.getCenter(find.text('Jobs')).dy;
    final menuY = tester.getCenter(find.byTooltip('How to draw “Jobs”')).dy;

    expect((titleY - menuY).abs(), lessThan(24));
  });
}

Future<void> _pumpDashboard(
  WidgetTester tester,
  List<Map<String, dynamic>> glances, {
  List<Map<String, dynamic>>? record,
}) async {
  tester.view.physicalSize = const Size(1400, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient((request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      record?.add(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'ok': true});
    }

    if (path.endsWith('/dashboard')) {
      return _json({
        'period': 'month',
        'period_starts_on': '2026-08-01T00:00:00-04:00',
        'period_ends_on': '2026-08-24T16:00:00-04:00',
        'glances': glances,
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  }));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: DashboardScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

List<Map<String, dynamic>> _glances({
  required String presentation,
  List<Map<String, dynamic>>? slices,
  List<Map<String, dynamic>>? series,
  bool empty = false,
  List<String> allowed = const ['pie', 'bar', 'text'],
  String sentence = '2 jobs on the board.',
}) {
  return [
    {
      'key': 'jobs',
      'label': 'Jobs',
      'presentation': presentation,
      'allowed_presentations': allowed,
      'snapshot': true,
      'coming_soon': false,
      'options': <String, dynamic>{},
      'data': {
        'empty': empty,
        'slices': ?slices,
        'series': ?series,
        if (empty) 'sentence': sentence else 'sentences': [sentence],
      },
    },
  ];
}

List<Map<String, dynamic>> _comingSoon() => [
  {
    'key': 'inventory',
    'label': 'Inventory',
    'presentation': 'text',
    'allowed_presentations': <String>[],
    'snapshot': true,
    'coming_soon': true,
    'options': <String, dynamic>{},
    'data': {
      'empty': true,
      'sentence':
          'Inventory is where the company will keep what it has on the shelf '
          '— parts, materials, and the stock that jobs draw from. The '
          'register is not here yet.',
    },
  },
];

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['jobs.view'],
  'modules': [
    {'key': 'jobs', 'label': 'Jobs', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};

/// The legend's colour dots, in slice order.
///
/// The legend is the readable surface for "which colour is this series" — it
/// is the same `colors` list the painter uses, and unlike the canvas it can be
/// asserted without pixel-reading. Scoped to the chart so no other Container
/// in the tree can be mistaken for a swatch.
Future<List<Color>> _legendColors(
  WidgetTester tester,
  List<Map<String, dynamic>> slices, {
  bool dark = false,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: dark ? EzBiziTheme.dark() : EzBiziTheme.light(),
      home: Scaffold(
        body: DashboardChart(presentation: 'pie', slices: slices),
      ),
    ),
  );
  await tester.pumpAndSettle();

  final dots = tester
      .widgetList<Container>(
        find.descendant(
          of: find.byType(DashboardChart),
          matching: find.byType(Container),
        ),
      )
      .map((c) => (c.decoration! as BoxDecoration).color!)
      .toList();

  expect(dots, isNotEmpty, reason: 'No legend swatches were found at all.');

  return dots;
}

/// What a screen reader is handed for the chart.
String _chartSemantics(WidgetTester tester) {
  final node = tester.widget<Semantics>(
    find
        .descendant(
          of: find.byType(DashboardChart),
          matching: find.byType(Semantics),
        )
        .first,
  );

  final label = node.properties.label ?? '';
  expect(label, isNotEmpty, reason: 'The chart exposed no semantics at all.');

  return label;
}

/// A pie on its own, sized so the geometry in the tests is predictable.
Future<void> _pumpPie(
  WidgetTester tester,
  List<Map<String, dynamic>> slices,
) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: DashboardChart(presentation: 'pie', slices: slices),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// A single mouse, already on the chart, that the test can move.
///
/// ONE pointer per test, not one per move: `addPointer` twice without a
/// remove between trips a MouseTracker assertion, so a helper that creates its
/// own gesture each call works for the first hover of a test and throws on the
/// second. Returns a mover so the pointer outlives the call.
///
/// A mouse specifically — `MouseRegion.onHover` is what this feature hangs on,
/// and a synthetic touch pointer would never fire it. A test using one would
/// pass against a feature no real mouse has.
Future<Future<void> Function(Offset)> _pointerOver(WidgetTester tester) async {
  final paint = find
      .descendant(
        of: find.byType(DashboardChart),
        matching: find.byType(CustomPaint),
      )
      .first;
  final centre = tester.getCenter(paint);

  final gesture = await tester.createGesture(kind: PointerDeviceKind.mouse);
  await gesture.addPointer(location: Offset.zero);
  addTearDown(gesture.removePointer);

  return (Offset fromCentre) async {
    await gesture.moveTo(centre + fromCentre);
    await tester.pumpAndSettle();
  };
}

/// The live painter off the tree.
///
/// Taken from the widget rather than constructed, because the painter type is
/// private to its library — which also means `shouldRepaint` can only ever be
/// asked with two instances the app itself made. That is the honest form of
/// the question anyway: did THIS rebuild tell the canvas to redraw.
CustomPainter _painterOf(WidgetTester tester) {
  final paint = tester.widgetList<CustomPaint>(
    find.descendant(
      of: find.byType(DashboardChart),
      matching: find.byType(CustomPaint),
    ),
  ).firstWhere((c) => c.painter != null);

  return paint.painter!;
}
