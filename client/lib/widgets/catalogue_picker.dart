/// One choosable thing — an event to listen for, or an action to run.
class PickerItem {
  const PickerItem({
    required this.key,
    required this.label,
    this.description,
    this.enabled = true,
    this.badge,
    this.note,
  });

  final String key;
  final String label;
  final String? description;

  /// False draws the row and refuses the tap. Used for actions the engine
  /// cannot run yet: they are part of what this system is going to be, and
  /// hiding them answers nobody's question about why an obvious action is
  /// missing. See [badge] — a disabled row without one is just a bug.
  final bool enabled;

  /// The word that says WHY a row looks different — "Not built yet".
  final String? badge;

  /// One measured line under the description, such as "23 times in the last
  /// 30 days". Null draws nothing: a missing number is not a zero.
  final String? note;
}

/// A named set of items — a module, or the group of actions that work anywhere.
class PickerGroup {
  const PickerGroup({
    required this.key,
    required this.label,
    required this.items,
  });

  final String key;
  final String label;
  final List<PickerItem> items;
}
