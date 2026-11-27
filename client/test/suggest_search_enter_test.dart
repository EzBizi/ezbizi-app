import 'package:ezbizi_client/widgets/suggest_search.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Audit 2026-11-27: Enter before the search delay picked a row from the
/// previous term's results (Inspection > New could load the wrong
/// customer). With a search pending, Enter runs it instead.
void main() {
  Future<(List<String>, List<int>)> pump(WidgetTester tester) async {
    final asked = <String>[];
    final picked = <int>[];
    final controller = TextEditingController();
    addTearDown(controller.dispose);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SuggestSearchField(
            controller: controller,
            // The rows of an older term, still on screen.
            suggestions: const [SuggestItem(title: 'Ann Older')],
            onChanged: asked.add,
            onPick: picked.add,
            debounce: const Duration(milliseconds: 300),
            enterPicksFirst: true,
          ),
        ),
      ),
    );
    return (asked, picked);
  }

  testWidgets('Enter during the delay searches, and picks nothing', (
    tester,
  ) async {
    final (asked, picked) = await pump(tester);
    await tester.enterText(find.byType(TextField), 'Bob');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pump();
    expect(asked, ['Bob']);
    expect(picked, isEmpty);
    await tester.pump(const Duration(milliseconds: 400));
    expect(asked, ['Bob'], reason: 'the cancelled delay does not ask again');
  });

  testWidgets('Enter after the delay still picks the first row', (
    tester,
  ) async {
    final (asked, picked) = await pump(tester);
    await tester.enterText(find.byType(TextField), 'Ann');
    await tester.pump(const Duration(milliseconds: 400));
    expect(asked, ['Ann']);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pump();
    expect(picked, [0]);
  });
}
