import 'package:flutter/widgets.dart';

/// Lets a screen send someone to another destination, such as an empty
/// state's "Open Settings" button when the next step lives somewhere else.
/// The UI review of 2026-10-08 found those steps written as text instead
/// ("Settings › Numbers"), because a screen had no way to get there.
///
/// It lands on the destination, not on a tab inside it: the address holds
/// destinations only.
class OpenDestination extends InheritedWidget {
  const OpenDestination({
    super.key,
    required this.open,
    this.openLead,
    required super.child,
  });

  /// A module key, or `settings`, `suggestions` or `account`.
  final void Function(String id) open;

  /// Null outside the signed-in shell, so a caller can leave its button out.
  static void Function(String id)? of(BuildContext context) =>
      context.getInheritedWidgetOfExactType<OpenDestination>()?.open;

  /// Leads with one lead open (Customers' search, 2026-10-16).
  final void Function(String leadId)? openLead;

  static void Function(String leadId)? leadOf(BuildContext context) =>
      context.getInheritedWidgetOfExactType<OpenDestination>()?.openLead;

  @override
  bool updateShouldNotify(OpenDestination old) => false;
}
