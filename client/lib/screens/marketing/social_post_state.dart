/// The six work states of a social post, as the server reads them
/// (App\Support\Social\PostState, suggestions 01a1ef57 / 01a1ef71).
///
/// Data only: the layout belongs to UI-UX. Every tile, row, calendar card and
/// inspector reads the post's `state` key through this, so a post is never
/// "Scheduled" in one place and "Needs approval" in another, and a creation
/// time is never shown as a plan.
enum SocialPostState {
  needsApproval('awaiting', 'Needs approval'),
  ready('ready', 'Ready to schedule'),
  scheduled('scheduled', 'Scheduled'),
  pastDue('past_due', 'Past due'),
  published('sent', 'Published'),
  failed('failed', 'Failed');

  const SocialPostState(this.key, this.label);

  /// The server's word: the `state` of a post and the key of its tile.
  final String key;
  final String label;

  /// Null for a post still sending, or one every account turned down.
  static SocialPostState? fromKey(String? key) {
    for (final state in values) {
      if (state.key == key) return state;
    }
    return null;
  }

  /// Still to go out: the planner's Coming up / Needs attention.
  bool get upcoming =>
      this == needsApproval ||
      this == ready ||
      this == scheduled ||
      this == pastDue;

  /// What clears it, in a few words, for the inspector's primary action.
  String get nextStep => switch (this) {
    needsApproval => 'Review and approve',
    ready => 'Set a time',
    scheduled => 'Reschedule',
    pastDue => 'Pick a new time',
    published => 'Open the post',
    failed => 'Retry or reconnect',
  };
}

/// The promised wall time ("2026-11-20 09:00:00"), never `created_at`. Null
/// when no time is set: such a post belongs in Needs attention, not on a day.
DateTime? socialPlannedTime(String? scheduledFor) => scheduledFor == null
    ? null
    : DateTime.tryParse(scheduledFor.replaceFirst(' ', 'T'));

/// Approve's honest label: it posts now only when no time is set, and is not
/// offered once the planned time has passed (the server answers 422).
String? socialApproveLabel(SocialPostState? state, String? scheduledFor) {
  if (state == SocialPostState.pastDue) return null;
  return socialPlannedTime(scheduledFor) == null
      ? 'Approve and post now'
      : 'Approve for the planned time';
}
