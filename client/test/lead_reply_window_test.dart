import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:flutter_test/flutter_test.dart';

/// The reply window under a Leads row's time (UI-UX, 2026-11-09).
void main() {
  final now = DateTime.utc(2026, 11, 9, 12);
  String at(Duration d) => now.add(d).toIso8601String();

  test('hours, then caution under two, then minutes, then closed', () {
    expect(replyWindowNote(at(const Duration(hours: 3, minutes: 10)), now), (
      '3h left',
      null,
    ));
    expect(replyWindowNote(at(const Duration(hours: 1, minutes: 59)), now), (
      '1h left',
      Tone.caution,
    ));
    expect(replyWindowNote(at(const Duration(minutes: 45)), now), (
      '45m left',
      Tone.caution,
    ));
    expect(replyWindowNote(at(const Duration(seconds: 20)), now), (
      '1m left',
      Tone.caution,
    ));
    expect(replyWindowNote(at(const Duration(days: 6, hours: 2)), now), (
      '6d left',
      null,
    ));
    expect(replyWindowNote(at(-const Duration(minutes: 1)), now), (
      'Window closed',
      null,
    ));
  });

  test('no window, no note', () {
    expect(replyWindowNote(null, now), isNull);
    expect(replyWindowNote('not a date', now), isNull);
  });
}
