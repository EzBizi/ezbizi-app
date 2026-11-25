import 'package:ezbizi_client/widgets/phone_bottom_bar.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

/// iPhone Duo on its side (593 pt beside its status column): five slots of
/// ~118 px read "Schedu…" while the icon kept its 52 px pill. The test font
/// draws every glyph 11 px wide, so six letters (66 px) stand in for
/// "Scheduling": they fit the 74 px left beside a 36 px pill, not the 58 px
/// beside a 52 px one.
void main() {
  testWidgets('sideways, a one-word label stays whole in its slot', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(593, 466);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          bottomNavigationBar: PhoneBottomBar(
            items: [
              PhoneBarItem(icon: Icons.build, label: 'Jobs'),
              PhoneBarItem(icon: Icons.event, label: 'Agenda'),
              PhoneBarItem(icon: Icons.call_received, label: 'Leads'),
              PhoneBarItem(icon: Icons.call, label: 'Comms'),
              PhoneBarItem(icon: Icons.menu, label: 'More'),
            ],
          ),
        ),
      ),
    );
    final label = tester.renderObject<RenderParagraph>(find.text('Agenda'));
    expect(label.didExceedMaxLines, isFalse);
  });
}
