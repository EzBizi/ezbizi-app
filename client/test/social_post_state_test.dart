import 'package:ezbizi/screens/marketing/social_post_state.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('every server state key reads back, and unknown or sending is null', () {
    for (final state in SocialPostState.values) {
      expect(SocialPostState.fromKey(state.key), state);
    }
    expect(SocialPostState.fromKey(null), isNull);
    expect(SocialPostState.fromKey('queued'), isNull);
  });

  test('the Washington draft: awaiting, no time, is upcoming and unplanned', () {
    final state = SocialPostState.fromKey('awaiting')!;
    expect(state.label, 'Needs approval');
    expect(state.upcoming, isTrue);
    expect(socialPlannedTime(null), isNull);
    expect(socialApproveLabel(state, null), 'Approve and post now');
  });

  test('a planned time is the saved wall time; a past one cannot be approved', () {
    expect(socialPlannedTime('2026-11-20 09:00:00'), DateTime(2026, 11, 20, 9));
    expect(
      socialApproveLabel(SocialPostState.needsApproval, '2026-11-20 09:00:00'),
      'Approve for the planned time',
    );
    expect(socialApproveLabel(SocialPostState.pastDue, '2026-11-01 09:00:00'), isNull);
    expect(SocialPostState.published.upcoming, isFalse);
    expect(SocialPostState.failed.upcoming, isFalse);
  });
}
