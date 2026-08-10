import 'package:flutter/material.dart';

/// A simple segmented row of destinations.
///
/// Not a real TabBar: that needs a TabController and a DefaultTabController
/// ancestor, and the screens using this already keep their selection in one
/// obvious place of their own.
///
/// It sits here rather than beside its first caller because there are two now —
/// the Development section's sub-navigation, and the Automations screen's split
/// between the list and the builder. A second copy is how the two would drift
/// into looking like different controls doing the same job.
class TabBarLike extends StatelessWidget {
  const TabBarLike({
    super.key,
    required this.labels,
    required this.selected,
    required this.onSelected,
  });

  final List<(String, IconData)> labels;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          for (var i = 0; i < labels.length; i++)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                avatar: Icon(labels[i].$2, size: 16),
                label: Text(labels[i].$1),
                selected: i == selected,
                onSelected: (_) => onSelected(i),
              ),
            ),
        ],
      ),
    );
  }
}
