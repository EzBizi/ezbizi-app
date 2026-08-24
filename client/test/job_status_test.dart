import 'package:ezbizi_client/core/job_status.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('a string list is still a list of statuses', () {
    final parsed = parseJobStatuses(['scheduled', 'in_progress']);

    expect(parsed.map((s) => s.value).toList(), ['scheduled', 'in_progress']);
    expect(parsed.map((s) => s.label).toList(), ['Scheduled', 'In Progress']);
  });

  test('a named list keeps the words people see', () {
    final parsed = parseJobStatuses([
      {'value': 'booked', 'label': 'Booked'},
      {'value': 'in_progress', 'label': 'In progress'},
    ]);

    expect(parsed.first.value, 'booked');
    expect(parsed.first.label, 'Booked');
  });
}
