import 'package:ezbizi_client/core/session.dart';
import 'package:flutter_test/flutter_test.dart';

/// The schedule keys on the `time_clock` block (design 2026-09-08 §9).
void main() {
  test('an old server without the schedule keys parses with safe defaults', () {
    final status = TimeClockStatus.fromJson({
      'organization_enabled': true,
      'can_clock': true,
      'show_button': true,
      'open': null,
    });

    expect(status.lock, isFalse);
    expect(status.scheduledEndAt, isNull);
    expect(status.warningMinutes, 5);
    expect(status.extensionMinutes, 30);
    expect(status.today, isNull);
  });

  test('the schedule keys parse, and the end instant is UTC', () {
    final status = TimeClockStatus.fromJson({
      'organization_enabled': true,
      'can_clock': true,
      'show_button': true,
      'open': {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
      'lock': true,
      'scheduled_end_at': '2026-09-08T22:00:00Z',
      'warning_minutes': 3,
      'extension_minutes': 45,
      'today': {'start': '08:00', 'end': '17:00'},
    });

    expect(status.lock, isTrue);
    expect(status.scheduledEndAt, DateTime.utc(2026, 9, 8, 22));
    expect(status.scheduledEndAt!.isUtc, isTrue);
    expect(status.warningMinutes, 3);
    expect(status.extensionMinutes, 45);
    expect(status.today!.start, '08:00');
    expect(status.today!.end, '17:00');
  });
}
