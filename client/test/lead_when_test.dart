import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('an older lead date is short enough for the list column', () {
    final now = DateTime.now();
    final old = DateTime(now.year - 1, 10, 5, 12);
    expect(
      leadWhen(old.toIso8601String()),
      "Oct 5 '${(old.year % 100).toString().padLeft(2, '0')}",
    );
    expect(leadWhen(old.toIso8601String()).length, lessThanOrEqualTo(9));
  });
}
