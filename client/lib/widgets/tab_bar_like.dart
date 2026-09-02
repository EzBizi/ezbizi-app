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
                // No checkmark, because it is not drawn BESIDE the icon — it
                // is drawn ON it.
                //
                // A Material chip that has both an avatar and a checkmark
                // paints the tick over the avatar, and darkens the avatar
                // first so the tick can be read against it — Flutter's own
                // `_kSelectScrimColor`, a 37% black filled through
                // `avatarBorder`, which is a circle. So the selected tab's own
                // icon disappeared under a grey disc, and because it follows
                // the SELECTION rather than
                // the pointer it appeared on a tab nobody was hovering — which
                // is exactly how it was reported: "regardless if I'm hovering
                // over that tab or any tab".
                //
                // Nothing is lost by removing it. A selected chip already
                // carries a filled background, and the icon is the thing that
                // tells the tabs apart — covering it to say "this one" is
                // spending the identifier to state what the fill has said.
                showCheckmark: false,
                onSelected: (_) => onSelected(i),
              ),
            ),
        ],
      ),
    );
  }
}
