import 'package:ezbizi_client/core/mask.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('masks the local part and keeps the domain', () {
    expect(maskEmail('jose@example.com'), 'j•••@example.com');
    expect(maskEmail('not-an-email'), 'not-an-email');
  });
  test('keeps the last four digits of a phone', () {
    expect(maskPhone('+1 (786) 555-0100'), '•••-0100');
  });
}
