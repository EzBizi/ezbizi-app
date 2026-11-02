import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/ui_strings.dart';
import 'package:ezbizi_client/widgets/module_page_header.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The module title block — approved as desktop layout 1 with mobile layout 5.
///
/// The two are one design with one deliberate difference, and that difference
/// is the thing worth holding still in a test.
void main() {
  // Design 161 T1: the subtitle is the (i) tooltip beside the title.
  testWidgets('a desktop shows the subtitle as the (i) tooltip', (tester) async {
    await _pump(tester, width: 1200);

    expect(find.text('Human Resources'), findsOneWidget);
    expect(find.byTooltip('Everyone on the books.'), findsOneWidget);
  });

  testWidgets('a phone shows the title and drops the subtitle', (tester) async {
    await _pump(tester, width: 380);

    // The title is the whole reason the header exists on a phone: the sidebar
    // is a Drawer there, so until you open it nothing on screen names the
    // module.
    expect(find.text('Human Resources'), findsOneWidget);

    // And the subtitle is a sentence — about a fifth of a 375px screen, read
    // once ever by each person, sitting above the thing they came for.
    expect(
      find.text('Everyone on the books.'),
      findsNothing,
      reason:
          'Mobile layout 5 drops the subtitle. Keeping it is what makes a '
          'phone header the desktop one narrowed, which is what this pair of '
          'layouts exists not to be.',
    );
  });

  testWidgets('the phone title is smaller, and both come from one token', (
    tester,
  ) async {
    await _pump(tester, width: 1200);
    final wide = tester.widget<Text>(find.text('Human Resources'));

    await _pump(tester, width: 380);
    final narrow = tester.widget<Text>(find.text('Human Resources'));

    expect(wide.style?.fontSize, 28);
    expect(narrow.style?.fontSize, 22);
    expect(
      narrow.style?.fontWeight,
      wide.style?.fontWeight,
      reason:
          'Both are headlineMedium with the size overridden, not two separate '
          'hardcoded styles. A fifth size typed by hand is how this started.',
    );
  });

  testWidgets('actions sit in the header on both', (tester) async {
    await _pump(tester, width: 1200, withAction: true);
    expect(find.byIcon(Icons.refresh), findsOneWidget);

    await _pump(tester, width: 380, withAction: true);
    expect(find.byIcon(Icons.refresh), findsOneWidget);
  });

  test('a module this build has never heard of gets no subtitle', () {
    // A server newer than the app sends module keys this build has no string
    // for. `t` would return the key, and "module.forecasting.subtitle" printed
    // under a page title is worse than nothing at all.
    expect(UiStrings.maybe('module.forecasting.subtitle'), isNull);

    // The ones it does know still answer, or the check above passes for the
    // wrong reason.
    expect(UiStrings.maybe('module.hr.subtitle'), isNotNull);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required double width,
  bool withAction = false,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SizedBox(
          width: width,
          child: ModulePageHeader(
            title: 'Human Resources',
            subtitle: 'Everyone on the books.',
            actions: [
              if (withAction)
                IconButton(onPressed: () {}, icon: const Icon(Icons.refresh)),
            ],
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
