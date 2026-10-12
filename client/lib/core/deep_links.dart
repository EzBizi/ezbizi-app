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
  });

  /// The module key that should open it, e.g. `email`.
  final String module;

  /// What kind of record, e.g. `email_message`, `call`,
  /// `time_clock_correction_request`, `field_alert`, `automation`.
  final String type;

  final String id;

  /// For an `email_message`: the address of the mailbox it arrived in.
  final String? mailbox;

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
}
