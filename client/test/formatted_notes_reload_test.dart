import 'package:ezbizi_client/widgets/formatted_notes.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('setting markup refills text and styles, not tags as letters', () {
    final c = FormattedNotesController(markup: 'old');
    c.markup = 'Oil <b>leak</b> left';

    expect(c.text, 'Oil leak left');
    expect(c.markup, 'Oil <b>leak</b> left');

    c.markup = null;
    expect(c.text, '');
    expect(c.markup, '');
  });
}
