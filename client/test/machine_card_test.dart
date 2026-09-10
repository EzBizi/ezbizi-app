import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/machine_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The PC Specs grid's card and its load meters.
///
/// The load-bearing tests here are the two about ABSENCE: a machine that has
/// not said how busy it is must not render as an idle one, and a machine whose
/// owner is exempt must not render as a broken one. Both are cases where the
/// wrong answer looks exactly like a normal reading.
void main() {
  Widget wrap(Widget child) => MaterialApp(
        home: Scaffold(body: SizedBox(width: 420, child: child)),
      );

  MachineCard card({
    bool online = true,
    bool exempt = false,
    int? cpu,
    int? ram,
    int? disk,
    String? activeWindow,
    String? sessions,
  }) =>
      MachineCard(
        title: 'DESKTOP-AI4K9IP',
        person: 'Marco Reyes',
        online: online,
        seenLabel: '3m ago',
        exempt: exempt,
        cpuPercent: cpu,
        ramPercent: ram,
        diskPercent: disk,
        activeWindow: activeWindow,
        sessions: sessions,
      );

  testWidgets('a reading it does not have renders as a dash, never as zero',
      (tester) async {
    await tester.pumpWidget(wrap(card(cpu: null, ram: 41, disk: 12)));

    // The one that matters: 0% is a claim that the machine is idle, and an
    // agent that has not reported has made no claim at all. An older agent
    // reports no cpu_percent on its first read by design.
    expect(find.text('0%'), findsNothing);
    expect(find.textContaining('—'), findsOneWidget);

    // Control: the readings it DOES have are rendered, so the dash above is
    // the missing value and not a card that renders no numbers at all.
    expect(find.textContaining('41%'), findsOneWidget);
    expect(find.textContaining('12%'), findsOneWidget);
  });

  testWidgets('an exempt machine says Protected rather than looking broken',
      (tester) async {
    await tester.pumpWidget(wrap(card(exempt: true)));
    expect(find.text('Protected'), findsOneWidget);

    // Control: the badge is the exemption, not something every card carries.
    await tester.pumpWidget(wrap(card(exempt: false)));
    expect(find.text('Protected'), findsNothing);
  });

  testWidgets('a live machine says Live, an offline one says when it was seen',
      (tester) async {
    await tester.pumpWidget(wrap(card(online: true)));
    expect(find.text('Live'), findsOneWidget);
    expect(find.text('3m ago'), findsNothing);

    await tester.pumpWidget(wrap(card(online: false)));
    expect(find.text('Live'), findsNothing);
    expect(find.text('3m ago'), findsOneWidget);
  });

  testWidgets('the session count survives, so a second machine is not hidden',
      (tester) async {
    await tester.pumpWidget(wrap(card(sessions: '1 active · 20 earlier')));
    expect(find.text('1 active · 20 earlier'), findsOneWidget);
  });

  testWidgets('the active window shows when there is one, and nothing when not',
      (tester) async {
    await tester.pumpWidget(wrap(card(activeWindow: 'Interviews - Chrome')));
    expect(find.text('Interviews - Chrome'), findsOneWidget);

    // A viewer without the screenshot grant is not sent it at all, and an
    // empty line where it would go is its own small lie.
    await tester.pumpWidget(wrap(card(activeWindow: null)));
    expect(find.text('Interviews - Chrome'), findsNothing);
  });

  testWidgets('a meter is never colour alone: the number is always written',
      (tester) async {
    await tester.pumpWidget(wrap(card(cpu: 91, ram: 70, disk: 4)));

    // Whatever the bar is doing, each reading is legible as text — the same
    // rule the status bolt follows.
    expect(find.textContaining('91%'), findsOneWidget);
    expect(find.textContaining('70%'), findsOneWidget);
    expect(find.textContaining('4%'), findsOneWidget);
  });

  testWidgets('the three meter colours are three different colours',
      (tester) async {
    late BuildContext ctx;
    await tester.pumpWidget(MaterialApp(
      home: Builder(builder: (context) {
        ctx = context;
        return const SizedBox();
      }),
    ));

    final calm = MeterBar.colourFor(ctx, MeterBar.amberAt - 1);
    final warn = MeterBar.colourFor(ctx, MeterBar.amberAt);
    final bad = MeterBar.colourFor(ctx, MeterBar.redAt);

    expect(calm, EzBiziTheme.clockIn(ctx));
    expect(warn, EzBiziTheme.meterWarning(ctx));
    expect(bad, Theme.of(ctx).colorScheme.error);

    // The point of three steps is that they are distinguishable. A theme that
    // resolved two of them to the same value would leave "getting full" and
    // "full" reading identically, which is the thing the middle step exists
    // to prevent.
    expect({calm, warn, bad}.length, 3,
        reason: 'Two meter colours resolved to the same value, so one of the '
            'three states cannot be seen.');
  });
}
