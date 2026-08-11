/// The label a dashboard row is drawn with.
///
/// ── Why it is not just `row['title']` ────────────────────────────
///
/// Some widgets count over a rolling window and some do not, and one widget can
/// do both at once. HR's starters-and-leavers reports joining over the last N
/// days and "no longer active" as a running total, because `users.is_active` is
/// a boolean with no timestamp beside it — nothing records the DAY somebody was
/// switched off, so there is no honest answer to "who left this month".
///
/// So the SERVER says which rows the window covers (`period_applies_to`) and
/// how long it is (`days`), and this composes the label from that. A client
/// that decided for itself which row was the dated one would keep working until
/// a third row was added and then quietly mislabel it.
///
/// ── One function, because there are two surfaces ─────────────────
///
/// The HR tab draws a fixed set of these panels; the main dashboard draws
/// whatever somebody composed. The window was applied on the first and dropped
/// on the second, so the same figure read "Joined in the last 30 days" on one
/// screen and a bare "Joined" — an all-time count, to anybody reading it — on
/// the other. Two homes for one fact is the trap; this is the one home.
String dashboardRowLabel(Map<String, dynamic> data, Map<String, dynamic> row) {
  // `status` for the widgets whose rows are named after a job status, `title`
  // for the rest. Both are the row's NAME; neither carries a period.
  final title = (row['status'] ?? row['title'] ?? '').toString();

  final dated = data['period_applies_to'];
  final days = data['days'];

  // A widget that says nothing about a period gets its title back unchanged,
  // which is every widget that existed before this was written.
  if (dated is! List || days is! int || !dated.contains(title)) {
    return title;
  }

  // `days` is a number the server chose; nothing in the payload promises it is
  // more than one, and a one-day window is already a period a widget offers
  // (automations.health). "in the last 1 days" is the tell of a sentence glued
  // together without anybody reading it back.
  return days == 1
      ? '$title in the last day'
      : '$title in the last $days days';
}
