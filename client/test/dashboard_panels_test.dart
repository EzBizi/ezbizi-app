import 'package:ezbizi_client/core/dashboard_panels.dart';
import 'package:flutter_test/flutter_test.dart';

/// How a dashboard row's label is composed — the one place, for two screens.
///
/// ── Why these are unit tests and not another screen ──────────────
///
/// Both surfaces already prove they call this function rather than composing a
/// label of their own: dashboard_screen_test.dart and hr_dashboard_test.dart
/// each drive a real `/dashboard` and `/hr/dashboard` response through it. That
/// is the wiring, and it is tested where the wiring lives.
///
/// What is left is the sentence itself, and the sentence takes a NUMBER. Its
/// input is any `days` a widget can report, not the one window today's widgets
/// happen to send, and driving a screen with a response the server would not
/// produce in order to reach the other branches would be pinning a fixture
/// rather than a behaviour.
void main() {
  test('a row the window covers carries the window', () {
    expect(
      dashboardRowLabel(
        {'days': 30, 'period_applies_to': ['Joined']},
        {'title': 'Joined'},
      ),
      'Joined in the last 30 days',
    );
  });

  /// `days` is a number the server chose, and nothing in the payload promises
  /// it is more than one — `automations.health` already offers a one-day
  /// window. "in the last 1 days" is the tell of a sentence glued together
  /// without anybody reading it back.
  test('a one-day window is written as a day, not as 1 days', () {
    expect(
      dashboardRowLabel(
        {'days': 1, 'period_applies_to': ['Joined']},
        {'title': 'Joined'},
      ),
      'Joined in the last day',
    );
  });

  /// The failure that looks like success: a plausible number under a period it
  /// was never counted over. Nothing records the day somebody was deactivated,
  /// so "No longer active" is an all-time figure and must not inherit the
  /// window beside it.
  test('a row the window does not cover is left alone', () {
    expect(
      dashboardRowLabel(
        {'days': 30, 'period_applies_to': ['Joined']},
        {'title': 'No longer active'},
      ),
      'No longer active',
    );
  });

  /// Job widgets name their rows `status` and HR names them `title`. Both are
  /// the row's name, and neither carries a period of its own.
  test('a row named by status is composed the same way', () {
    expect(
      dashboardRowLabel(
        {'days': 7, 'period_applies_to': ['scheduled']},
        {'status': 'scheduled'},
      ),
      'scheduled in the last 7 days',
    );
  });

  /// Every widget written before any of this existed. A payload that says
  /// nothing about a period gets its title back untouched.
  test('a widget that says nothing about a period gets a bare title', () {
    expect(
      dashboardRowLabel({'total': 4}, {'title': 'Joined'}),
      'Joined',
    );
  });
}
