import 'package:ezbizi_client/screens/marketing/clock_label.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('clockLabel', () {
    test('a fixed offset says so, and says which side of Greenwich', () {
      // The sign flip is the whole test. Etc/GMT+5 is UTC−5: POSIX runs the
      // Etc zones backwards, and a label reading "UTC+5" would be a second
      // wrong clock printed over the first.
      expect(clockLabel('Etc/GMT+5'), 'UTC−5, no daylight saving');
      expect(clockLabel('Etc/GMT-3'), 'UTC+3, no daylight saving');
    });

    test('a real zone is named by its place', () {
      expect(clockLabel('America/New_York'), 'New York');
      expect(clockLabel('Europe/Madrid'), 'Madrid');
    });

    test('nothing to say when the server named no zone', () {
      expect(clockLabel(null), '');
      expect(clockLabel('  '), '');
    });
  });
}
