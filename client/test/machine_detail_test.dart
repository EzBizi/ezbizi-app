import 'package:ezbizi_client/screens/monitoring/machine_detail.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The panel beside the PC Specs grid — specifically the Security section,
/// where Windows' own Security Center words are turned into ones a person
/// reading a roster would use.
///
/// The load-bearing test is the LAST one. Every other row in this panel is a
/// value passed through, so a wrong mapping shows up as obviously wrong text.
/// A security row is different: "On" is a claim that a machine is protected,
/// and the failure that matters is making that claim about a state nobody
/// anticipated. So the mapping must not have a fallback that returns a
/// reassuring word.
void main() {
  Widget wrap(Widget child) => MaterialApp(
        home: Scaffold(body: SingleChildScrollView(child: child)),
      );

  MachineDetail detail(Map<String, dynamic>? security) => MachineDetail(
        title: 'DESKTOP-AI4K9IP',
        person: 'Marco Reyes',
        specs: {
          'computer_name': 'DESKTOP-AI4K9IP',
          'security': ?security,
        },
        screenContext: null,
      );

  testWidgets('Windows security states read as words, not as its own jargon',
      (tester) async {
    await tester.pumpWidget(wrap(detail({
      'antivirus': 'good',
      'firewall': 'poor',
      'updates': 'snoozed',
    })));

    expect(find.text('On'), findsOneWidget);
    expect(find.text('Needs attention'), findsOneWidget);
    expect(find.text('Paused'), findsOneWidget);

    // Control: the raw values must not ALSO be on screen, or the mapping is
    // decoration over the jargon rather than a replacement for it.
    expect(find.text('good'), findsNothing);
    expect(find.text('poor'), findsNothing);
    expect(find.text('snoozed'), findsNothing);
  });

  testWidgets('a machine that reported no security block shows no section',
      (tester) async {
    await tester.pumpWidget(wrap(detail(null)));

    // Windows Server has no Security Center at all, and an older agent sends
    // nothing. "Antivirus: —" would invite somebody to go and fix a machine
    // that never said anything was wrong.
    expect(find.text('Security'), findsNothing);
    expect(find.text('Antivirus'), findsNothing);

    // Control: the panel itself rendered, so the absence above is the section
    // standing down rather than an empty widget.
    expect(find.text('DESKTOP-AI4K9IP'), findsWidgets);
  });

  testWidgets('a state we do not recognise is never reported as protected',
      (tester) async {
    // The one that matters. If Windows grows a fifth health value, or an
    // agent is ever changed to send something else, the wrong answer is "On":
    // it tells somebody a machine is covered on the strength of not knowing
    // what it said. Passed through verbatim instead — ugly on screen, which
    // is the correct amount of alarming.
    await tester.pumpWidget(wrap(detail({'antivirus': 'expired_licence'})));

    expect(find.text('On'), findsNothing,
        reason: 'An unknown state must never be rendered as protected.');
    expect(find.text('expired_licence'), findsOneWidget);
  });
}
