import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';


/// The placeholder a granted-but-unbuilt module draws.
///
/// Web Management was the last module using it and stopped on 2026-08-28; its
/// tab tests moved to web_management_test.dart. The widget stays, and so does
/// this test, because the next module granted before it is built needs the same
/// answer — and the reason ComingSoonScreen exists is written on it: a blank
/// frame reads as a broken app or as a lost permission, and both guesses are
/// worse than the truth.
void main() {
  testWidgets(
    'Coming soon lays out on a 375-point window without overflowing',
    (tester) async {
      tester.view.physicalSize = const Size(375, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: const Scaffold(
            body: ComingSoonScreen(
              title: 'Phone',
              icon: Icons.phone_outlined,
              what: 'Calling is not here yet.',
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('Coming soon'), findsOneWidget);
      expect(tester.getRect(find.text('Phone')).right, lessThanOrEqualTo(375));
    },
  );
}
