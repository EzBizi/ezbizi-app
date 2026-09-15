import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/machine_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The PC Specs grid's card and its load meters.
///
/// The load-bearing test here is the one about ABSENCE: a machine that has not
/// said how busy it is must not render as an idle one — the case where the
/// wrong answer looks exactly like a normal reading.
void main() {
  Widget wrap(Widget child) => MaterialApp(
        home: Scaffold(body: SizedBox(width: 420, child: child)),
      );

  MachineCard card({
    bool online = true,
    bool reserveActivityLine = false,
    int? cpu,
    int? ram,
    int? gpu,
    bool needsAttention = false,
    int? disk,
    String? activeWindow,
    String? sessions,
  }) =>
      MachineCard(
        title: 'DESKTOP-AI4K9IP',
        person: 'Marco Reyes',
        online: online,
        seenLabel: '3m ago',
        reserveActivityLine: reserveActivityLine,
        cpuPercent: cpu,
        ramPercent: ram,
        gpuPercent: gpu,
        needsAttention: needsAttention,
        diskPercent: disk,
        activeWindow: activeWindow,
        sessions: sessions,
      );

  testWidgets('a reading it does not have renders as a dash, never as zero',
      (tester) async {
    // gpu supplied so that exactly ONE reading is missing — the assertion
    // below counts dashes, and it is about the CPU's absence specifically.
    // Loosening it to "two or more" when the GPU meter arrived would have
    // quietly stopped it being about anything.
    await tester.pumpWidget(wrap(card(cpu: null, ram: 41, disk: 12, gpu: 63)));

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

  testWidgets('the graphics load is its own meter, and absent is not idle',
      (tester) async {
    await tester.pumpWidget(wrap(card(cpu: 9, ram: 41, gpu: 63)));
    expect(find.text('GPU'), findsOneWidget);
    expect(find.textContaining('63%'), findsOneWidget);

    // The case that will actually happen: a machine whose counters name no 3D
    // engine, and every machine on its agent's FIRST read. Reporting 0% there
    // would say the card is idle, which is a claim nobody made.
    await tester.pumpWidget(wrap(card(cpu: 9, ram: 41, gpu: null)));
    expect(find.text('GPU'), findsOneWidget);
    expect(find.textContaining('63%'), findsNothing);
    expect(find.text('0%'), findsNothing);
  });

  testWidgets('a machine wanting attention says so on the card, not only inside',
      (tester) async {
    await tester.pumpWidget(wrap(card(needsAttention: true)));
    expect(find.text('Needs attention'), findsOneWidget);

    // Control, and the point of the whole design: the badge appears ONLY when
    // something is wrong. A badge confirming that nothing is wrong would sit
    // on forty-one of forty-two cards saying nothing, which is how people
    // learn to stop reading badges.
    await tester.pumpWidget(wrap(card(needsAttention: false)));
    expect(find.text('Needs attention'), findsNothing);
  });

  testWidgets('both badges fit a narrow card without overflowing',
      (tester) async {
    // 180px, not the 300px the grid actually drops to: at 300 this passed
    // while the real grid was overflowing by 12 pixels, so it was proving
    // nothing. Sized to genuinely stress the header, and sabotage-checked —
    // forcing the badge area back to an unbounded flex makes this red.
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SizedBox(
          width: 180,
          child: MachineCard(
            title: 'DESKTOP-AI4K9IP',
            person: 'Adeline Pierrelouis-Aghaji',
            online: true,
            seenLabel: 'just now',
            needsAttention: true,
          ),
        ),
      ),
    ));

    expect(tester.takeException(), isNull);
    expect(find.text('Live'), findsOneWidget);
    expect(find.text('Needs attention'), findsOneWidget);
  });

  testWidgets('the person leads the card and the machine sits under them',
      (tester) async {
    await tester.pumpWidget(wrap(card()));

    // Both are on the card; the test is which one is the HEADING. Asserting
    // only that both appear would pass with them in either order, which is
    // the whole of what this change was.
    final person = tester.widget<Text>(find.text('Marco Reyes'));
    final machine = tester.widget<Text>(find.text('DESKTOP-AI4K9IP'));
    final theme = Theme.of(tester.element(find.text('Marco Reyes')));

    expect(person.style, theme.textTheme.titleSmall,
        reason: 'A roster is read to find a person, so the person is the title.');
    expect(machine.style, theme.textTheme.labelSmall,
        reason: 'The machine qualifies the answer, it is not what is looked up.');
  });

  testWidgets('every card in a grid is the same height, with or without a window',
      (tester) async {
    // The bug this pins: cards with no active window came up a line short, so
    // the offline ones looked like a rendering fault rather than like
    // machines that had reported less.
    await tester.pumpWidget(wrap(card(
      reserveActivityLine: true,
      activeWindow: 'Workiz - Estimate - Microsoft Edge',
    )));
    final withWindow = tester.getSize(find.byType(Card)).height;

    await tester.pumpWidget(wrap(card(reserveActivityLine: true)));
    final without = tester.getSize(find.byType(Card)).height;

    expect(without, withWindow,
        reason: 'A card with nothing in front still holds the line open.');

    // Control: the reservation is a CHOICE the grid makes, not something
    // every card pays for — with nothing anywhere to show, the row goes.
    await tester.pumpWidget(wrap(card(reserveActivityLine: false)));
    expect(tester.getSize(find.byType(Card)).height, lessThan(without),
        reason: 'Reserving a row nobody can fill is dead space on every card.');
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
