import 'package:flutter/foundation.dart';

/// "Open this exact record", handed from a notification to the module that
/// owns the record.
///
/// Bar, 2026-10-12 (via Jose): tapping a notification must open "the exact
/// thing" it is about — "a new email notification should open that email".
/// The server names the record on every notification (`meta.record`: a type
/// and an id, plus the mailbox for an email); this carries it to the screen.
///
/// ── Why a notifier, not a constructor argument on every screen ───
///
/// A dozen modules own their own screens, built by the shell from one table.
/// Threading a parameter through each constructor would mean editing every
/// owner's file at once to deliver one value. Instead the bell PUTS the link
/// here and switches module; the owning screen TAKES it — when it builds, and
/// whenever a new one arrives while it is already open. A screen that has not
/// learned to take one simply leaves it, and the person lands on the module's
/// home, which is exactly what happened before; nothing breaks while each
/// owner adopts it.
class DeepLink {
  const DeepLink({
    required this.module,
    required this.type,
    required this.id,
    this.mailbox,
    this.date,
  });

  /// The module key that should open it, e.g. `email`.
  final String module;

  /// What kind of record, e.g. `email_message`, `call`,
  /// `time_clock_correction_request`, `field_alert`, `automation`.
  final String type;

  final String id;

  /// For an `email_message`: the address of the mailbox it arrived in.
  final String? mailbox;

  /// For a `time_clock_day_approval`: the day it is about, `YYYY-MM-DD`.
  final String? date;

  @override
  String toString() => 'DeepLink($module, $type, $id)';
}

class DeepLinks {
  DeepLinks._();

  /// The link waiting to be opened, or null. Listen to it to hear new ones
  /// while a screen is already showing.
  static final ValueNotifier<DeepLink?> pending = ValueNotifier<DeepLink?>(null);

  /// Hand a link to whichever screen owns [link.module].
  static void open(DeepLink link) => pending.value = link;

  /// The pending link for [module], consumed — or null when the waiting link
  /// belongs to another module (it stays for that one) or there is none.
  ///
  /// Consuming matters: a screen rebuilt later must not open the same record
  /// a second time, behind the person's back.
  static DeepLink? take(String module) {
    final link = pending.value;
    if (link == null || link.module != module) return null;
    pending.value = null;
    return link;
  }

  /// A web address that names a record, or null. `/scheduling/overbooking?
  /// date=Y-m-d` is the link in the day-before under-filled email
  /// (SchedulingWarnUnderfilled): Scheduling › Overbooking › Booked vs
  /// available on that day. Takes the URI so a test can hand it one.
  static DeepLink? fromUri(Uri uri) {
    final segments = uri.pathSegments.where((s) => s.isNotEmpty).toList();
    if (segments.length != 2 ||
        segments[0] != 'scheduling' ||
        segments[1] != 'overbooking') {
      return null;
    }
    final date = uri.queryParameters['date'];
    final valid = date != null && RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(date);

    return DeepLink(
      module: 'scheduling',
      type: 'booked_vs_available',
      id: valid ? date : '',
      date: valid ? date : null,
    );
  }
}
