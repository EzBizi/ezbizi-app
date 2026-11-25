import 'package:ezbizi_client/widgets/formatted_notes.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('an old plain note reads back exactly', () {
    const old = 'Call 2 < 3 times *first*\nthen leave';
    expect(NotesMarkup.plain(old), old);
    expect(FormattedNotesController(markup: old).markup, old);
  });

  test('markup round-trips and plain strips it', () {
    const m = 'Price <b>\$90</b> or <i><u>ask</u></i>';
    expect(FormattedNotesController(markup: m).markup, m);
    expect(NotesMarkup.plain(m), 'Price \$90 or ask');
  });

  testWidgets('select a word, Ctrl+B bolds it; typing after keeps bold', (
    tester,
  ) async {
    final c = FormattedNotesController(markup: 'Pay cash');
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: FormattedNotesField(controller: c)),
      ),
    );
    await tester.tap(find.byType(TextField));
    c.selection = const TextSelection(baseOffset: 4, extentOffset: 8);
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.keyB);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    expect(c.markup, 'Pay <b>cash</b>');

    c.selection = const TextSelection.collapsed(offset: 8);
    c.value = c.value.copyWith(
      text: 'Pay cash!',
      selection: const TextSelection.collapsed(offset: 9),
    );
    expect(c.markup, 'Pay <b>cash!</b>');

    await tester.tap(find.byKey(const ValueKey('notes-format-2')));
    c.selection = const TextSelection(baseOffset: 0, extentOffset: 3);
    await tester.tap(find.byKey(const ValueKey('notes-format-2')));
    expect(c.markup, '<i>Pay</i> <b>cash!</b>');
  });
}
