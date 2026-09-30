import 'package:ezbizi_client/core/schedule_words.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const twelve = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
  const zone = 'America/New_York';

  String? words(Map<String, dynamic> s, [DisplayFormats f = twelve]) =>
      scheduleWords({'time': '20:00', 'timezone': zone, ...s}, f);

  test('a weekly rule names its days, its time and its clock', () {
    expect(
      words({
        'kind': 'weekly',
        'days': ['sunday'],
      }),
      'Sundays 8:00 PM (New York)',
    );
    expect(
      words({
        'kind': 'weekly',
        'days': ['thursday', 'monday'],
      }),
      'Mondays and Thursdays 8:00 PM (New York)',
    );
    expect(
      words({
        'kind': 'weekly',
        'days': ['monday', 'wednesday', 'friday'],
      }),
      'Mon, Wed, Fri 8:00 PM (New York)',
    );
    expect(
      words({
        'kind': 'weekly',
        'days': ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
      }),
      'Weekdays 8:00 PM (New York)',
    );
  });

  test('daily and monthly', () {
    expect(words({'kind': 'daily'}), 'Every day 8:00 PM (New York)');
    for (final (day, said) in [
      (1, '1st'),
      (2, '2nd'),
      (3, '3rd'),
      (4, '4th'),
      (11, '11th'),
      (12, '12th'),
      (13, '13th'),
      (21, '21st'),
      (22, '22nd'),
    ]) {
      expect(
        words({'kind': 'monthly', 'day_of_month': day}),
        'The $said of each month 8:00 PM (New York)',
      );
    }
  });

  test("the organization's clock, and nothing for no schedule", () {
    const iso = DisplayFormats(dateFormat: 'YYYY-MM-DD', timeFormat: '24h');
    expect(words({'kind': 'daily'}, iso), 'Every day 20:00 (New York)');
    expect(scheduleWords(null, twelve), isNull, reason: 'an event rule');
    expect(words({'kind': 'hourly'}), isNull);
    expect(words({'kind': 'weekly', 'days': <String>[]}), isNull);
  });
}
