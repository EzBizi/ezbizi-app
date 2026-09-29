import 'package:flutter/material.dart';

import 'not_filled_in.dart';

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
    this.missing,
  });

  final List<(String, IconData)> labels;
  final int selected;
  final ValueChanged<int> onSelected;

  /// How many boxes under each tab are empty, as the amber count beside its
  /// label (HR's record, 2026-09-29). None drawn at zero or when null.
  final List<int>? missing;

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
    //     records_tab         bare indexWhere, which answers -1
    //     phone_screen        an Offstage loop, so a stale index draws nothing
    //
    // The exception, and the one to copy: `hr_screen`'s `_activeTab` keeps a
    // string KEY and derives the index against the list being drawn this
    // build, so one getter feeds both the bar and the stack and they cannot
    // disagree. Its comment is worth the detour — the version before it fell
    // back in the bar ALONE and rendered a blank screen, and only a test
    // granting a SUBSET of tabs caught it.
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

    final chips = [
      for (var i = 0; i < labels.length; i++)
        ChoiceChip(
          avatar: Icon(labels[i].$2, size: 16),
          label: switch (missing?[i] ?? 0) {
            0 => Text(labels[i].$1),
            final count => Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(labels[i].$1),
                const SizedBox(width: 6),
                MissingCount(
                  count,
                  tooltip:
                      '$count ${count == 1 ? 'field' : 'fields'} '
                      'not filled in',
                ),
              ],
            ),
          },
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
    ];

    // Sideways only on a phone, where a thumb swipes the strip. Anywhere wider
    // the chips wrap: a mouse cannot drag a sideways strip, so on a desktop a
    // chip past the edge was out of reach (Jose, 2026-09-29, on the hours
    // review: "on desktop you should just be able to see all the websites").
    if (MediaQuery.sizeOf(context).width >= 600) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Align(
          alignment: AlignmentDirectional.centerStart,
          child: Wrap(spacing: 8, runSpacing: 8, children: chips),
        ),
      );
    }

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          for (final chip in chips)
            Padding(padding: const EdgeInsets.only(right: 8), child: chip),
        ],
      ),
    );
  }
}
