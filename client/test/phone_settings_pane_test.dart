import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/settings_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The shared head that thirteen phone settings panes are built on.
///
/// It takes a `trailing` — a pane-level action like "New mailbox", or the
/// account picker Numbers draws when an organization has more than one phone
/// account. That trailing was a bare child of a Row, which means it arrived
/// with `maxWidth: infinity`.
///
/// Numbers passed a Row with an `Expanded` inside it. Under an unbounded width
/// that is the combination Flutter refuses outright — "children have non-zero
/// flex but incoming width constraints are unbounded" — and in DEBUG it throws.
/// In a release build the assertion is skipped, the layout degrades instead,
/// and the title's own Expanded is squeezed to a few pixels: "Numbers" came out
/// one letter per line, straight down the page.
///
/// So it shipped, and no test saw it. It is drawn only for an organization with
/// two or more phone accounts, which is a condition no test set up.
void main() {
  testWidgets('a trailing that wants the whole row cannot take it', (
    tester,
  ) async {
    await _pump(tester, trailing: _greedy());

    expect(
      tester.takeException(),
      isNull,
      reason:
          'An unbounded width reached a Row with an Expanded in it. In debug '
          'that throws; in release it silently eats the header.',
    );

    // Height, not width. `getRect` on a Text reports the size it PAINTED at,
    // which for a short word is its intrinsic width either way — a squeezed
    // title is not narrower, it is TALLER, because it wraps. One line of
    // titleMedium is about 24px; "Numbers" down the page was seven of them.
    expect(
      tester.getRect(find.text('Numbers')).height,
      lessThan(40),
      reason:
          'The title wrapped, so the trailing has squeezed it. This is exactly '
          'what shipped: one character per line, straight down the page.',
    );
  });

  testWidgets('a trailing that sizes itself is left alone', (tester) async {
    // What the picker looks like after the fix: min-size, fixed-width control.
    await _pump(
      tester,
      trailing: const SizedBox(width: 260, height: 44),
    );

    expect(tester.takeException(), isNull);

    // Flexible is LOOSE, so a trailing that asks for less than its share keeps
    // its own size and the title gets the rest — the hardening puts a ceiling
    // on a greedy trailing without shrinking a well-behaved one.
    expect(tester.getRect(find.byType(SizedBox).last).width, 260);
    expect(tester.getRect(find.text('Numbers')).height, lessThan(40));
  });
}

/// The shape Numbers shipped: a Row with an Expanded, handed to `trailing`.
Widget _greedy() => Row(
  children: [
    const Text('Phone account'),
    const SizedBox(width: 12),
    Expanded(
      child: DropdownButtonFormField<String>(
        initialValue: 'a',
        isExpanded: true,
        items: const [
          DropdownMenuItem(value: 'a', child: Text('A-1 Chimney')),
        ],
        onChanged: (_) {},
      ),
    ),
  ],
);

Future<void> _pump(WidgetTester tester, {required Widget trailing}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SizedBox(
          width: 1000,
          child: SettingsPane(
            title: 'Numbers',
            subtitle: 'The lines this business answers on.',
            trailing: trailing,
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
