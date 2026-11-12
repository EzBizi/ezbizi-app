import 'package:ezbizi_client/screens/settings_screen.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('nonGsm names what turns a text into UCS-2', () {
    expect(nonGsm('Hi {name}, 8-11 AM. Reply YES or NO.'), isEmpty);
    expect(nonGsm('2:00 PM–5:00 PM, it’s “yes” 👍'), [
      '–',
      '’',
      '“',
      '”',
      '👍',
    ]);
  });
}
