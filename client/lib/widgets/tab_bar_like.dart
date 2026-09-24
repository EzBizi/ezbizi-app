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
    // SIX CALLERS INVENTED SIX ANSWERS TO THE SAME QUESTION.
    //
    // This widget speaks positions, and most of its callers build `labels`
    // with a collection-`if` on a permission — so the list is a different
    // length for different people, and a stored index means a different tab.
    // Measured 2026-09-24: of 20 call sites, 16 filter their list, and every
    // one of them defends itself differently.
    //
    //     inspection_screen   _tab.clamp(0, tabs.length - 1), plus isEmpty
    //     reports_screen      resolves by NAME, three length guards
    //     leads_screen        indexWhere(...) + 1, so -1 lands on "All"
    //     hr_screen           converts index<->key at the call site
    //     records_tab         bare indexWhere, which answers -1
    //     phone_screen        an Offstage loop, so a stale index draws nothing
    //
    // What they have in common is the failure: nothing throws. An index past
    // the end lights no chip and paints a blank pane, and a screen that draws
    // its body from the same number disagrees with its own strip. That is a
    // bug report saying "the tab went blank" with no stack to read.
    //
    // So the contract gets stated once, here, and breaks loudly in debug and
    // in tests instead of quietly in front of somebody. Release builds strip
    // this entirely — it changes no behaviour, it only stops the silence.
    assert(
      labels.isEmpty || (selected >= 0 && selected < labels.length),
      'TabBarLike was given selected=$selected for the ${labels.length} label(s) ${labels.map((l) => l.$1).toList()}. '
      'No chip will be lit and the caller drawing its body from the same '
      'index will paint nothing. This is what a filtered tab list does to '
      'a stored index: clamp it, resolve it by name, or keep the key '
      'rather than the position.',
    );

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
