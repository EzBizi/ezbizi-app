import 'package:ezbizi_client/widgets/async_body.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Mobile review 2026-11-13, P6: one page gutter, 16 px on a phone, so the
/// title, the chips and the list line up (Settings used 24, Jobs 16).
void main() {
  Future<void> phone(WidgetTester tester, Widget child) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: child)));
  }

  testWidgets('a panel header note starts 16 in on a phone', (tester) async {
    await phone(tester, const PanelHeader(note: 'Twelve people'));
    expect(tester.getTopLeft(find.text('Twelve people')).dx, 16);
  });

  testWidgets('a side panel body starts 16 in on a phone', (tester) async {
    await phone(
      tester,
      const SidePanel(title: 'Customer', child: Text('Body')),
    );
    expect(tester.getTopLeft(find.text('Body')).dx, 16);
  });
}
