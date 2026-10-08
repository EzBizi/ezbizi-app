import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

import '../core/theme.dart';
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
class TabBarLike extends StatefulWidget {
  const TabBarLike({
    super.key,
    required this.labels,
    required this.selected,
    required this.onSelected,
    this.missing,
    this.tones,
  });

  final List<(String, IconData)> labels;
  final int selected;
  final ValueChanged<int> onSelected;

  /// How many boxes under each tab are empty, as the amber count beside its
  /// label (HR's record, 2026-09-29). None drawn at zero or when null.
  final List<int>? missing;

  /// Each chip's icon in its tone's colour, for a status beside the name
  /// (the hours review, 2026-09-29: a website that changes, green).
  final List<Tone?>? tones;

  @override
  State<TabBarLike> createState() => _TabBarLikeState();
}

class _TabBarLikeState extends State<TabBarLike> {
  /// The phone strip's position, to know whether tabs lie past its right edge.
  final _scroll = ScrollController();
  final _keys = <int, GlobalKey>{};
  bool _more = false;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_measure);
    WidgetsBinding.instance.addPostFrameCallback((_) => _settle());
  }

  @override
  void didUpdateWidget(TabBarLike old) {
    super.didUpdateWidget(old);
    if (old.selected != widget.selected ||
        old.labels.length != widget.labels.length) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _settle());
    }
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void _measure() {
    final more =
        _scroll.hasClients &&
        _scroll.position.hasContentDimensions &&
        _scroll.offset < _scroll.position.maxScrollExtent - 1;
    if (more != _more && mounted) setState(() => _more = more);
  }

  /// UI audit, 2026-10-08: on a phone the selected tab could sit past the
  /// edge, and nothing said the strip went on. So the selected one is brought
  /// into view, and while tabs lie beyond the edge it fades out there.
  void _settle() {
    if (!mounted) return;
    // The strip itself is scrolled, never the page around it: so not
    // Scrollable.ensureVisible, which moves every scrollable above it.
    final box = _keys[widget.selected]?.currentContext?.findRenderObject();
    final viewport = box == null ? null : RenderAbstractViewport.maybeOf(box);
    if (box is RenderBox && viewport != null && _scroll.hasClients) {
      final target = viewport
          .getOffsetToReveal(box, 0.5)
          .offset
          .clamp(0.0, _scroll.position.maxScrollExtent);
      if ((target - _scroll.offset).abs() > 1) {
        _scroll
            .animateTo(
              target,
              duration: const Duration(milliseconds: 200),
              curve: Curves.easeOut,
            )
            .then((_) => _measure());
      }
    }
    _measure();
  }

  @override
  Widget build(BuildContext context) {
    final labels = widget.labels;
    final selected = widget.selected;
    final missing = widget.missing;
    final tones = widget.tones;
    final onSelected = widget.onSelected;
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
        // A tab, said as one: a ChoiceChip alone reaches a screen reader as
        // a checkbox (UI audit, 2026-10-08).
        Semantics(
          key: _keys.putIfAbsent(i, GlobalKey.new),
          container: true,
          button: true,
          selected: i == selected,
          label: switch (missing?[i] ?? 0) {
            0 => labels[i].$1,
            final count =>
              '${labels[i].$1}, $count ${count == 1 ? 'field' : 'fields'} '
                  'not filled in',
          },
          onTap: () => onSelected(i),
          excludeSemantics: true,
          child: ChoiceChip(
            avatar: Icon(
              labels[i].$2,
              size: 16,
              color: switch (tones?[i]) {
                final tone? => ToneColours.of(context, tone).ink,
                null => null,
              },
            ),
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

    // One shape whether or not it fades: swapping a wrapper in and out would
    // rebuild the strip and throw away where it was scrolled to.
    final opaque = Theme.of(context).colorScheme.surface;
    return ShaderMask(
      blendMode: BlendMode.dstIn,
      // Only the alpha counts under dstIn: opaque, then none past the edge.
      shaderCallback: (bounds) => LinearGradient(
        colors: [opaque, opaque, _more ? Colors.transparent : opaque],
        stops: const [0, 0.86, 1],
      ).createShader(bounds),
      child: SingleChildScrollView(
        controller: _scroll,
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(
          children: [
            for (final chip in chips)
              Padding(padding: const EdgeInsets.only(right: 8), child: chip),
            if (_more) const SizedBox.shrink(key: Key('tab-bar-more')),
          ],
        ),
      ),
    );
  }
}
