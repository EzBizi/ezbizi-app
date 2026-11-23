import 'dart:ui' show PointerDeviceKind;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

import '../core/theme.dart';
import 'not_filled_in.dart';
import 'side_panel.dart';

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
    this.level,
    this.wrap = false,
  });

  /// On a desk (600 px and wider), wrap onto more lines instead of scrolling
  /// sideways: for a row whose every chip must be on show at once, as the
  /// hours review's websites (Jose, 2026-09-29: "on desktop you should just
  /// be able to see all the websites"). Every other row scrolls.
  final bool wrap;

  final List<(String, IconData)> labels;
  final int selected;
  final ValueChanged<int> onSelected;

  /// How many boxes under each tab are empty, as the amber count beside its
  /// label (HR's record, 2026-09-29). None drawn at zero or when null.
  final List<int>? missing;

  /// Each chip's icon in its tone's colour, for a status beside the name
  /// (the hours review, 2026-09-29: a website that changes, green).
  final List<Tone?>? tones;

  /// Which row of tabs this is. Jose, 2026-11-13 (tab-style.html, option A):
  /// a module's own tabs are underlined words, and every row inside one is
  /// small pills, the way Google, Microsoft and Stripe draw pages vs filters.
  ///
  /// Null works the level out from where the row sits: inside a [SidePanel]
  /// (a record's own tabs) it is [TabLevel.secondary], so a customer record
  /// and a job record draw the same pills; anywhere else it is a module's own
  /// row (mobile review 2026-11-13, P4: the customer record was underlined
  /// while the job record was pills).
  final TabLevel? level;

  /// The level this row draws at, explicit or inferred.
  static TabLevel levelOf(BuildContext context, TabLevel? level) =>
      level ??
      (context.findAncestorWidgetOfExactType<SidePanel>() != null
          ? TabLevel.secondary
          : TabLevel.primary);

  @override
  State<TabBarLike> createState() => _TabBarLikeState();
}

class _TabBarLikeState extends State<TabBarLike> {
  /// The phone strip's position, to know whether tabs lie past its right edge.
  final _scroll = ScrollController();
  final _keys = <int, GlobalKey>{};
  bool _more = false;

  /// Tabs lie before the left edge (the strip has been scrolled along).
  bool _less = false;

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
    final less = _scroll.hasClients && _scroll.offset > 1;
    if ((more != _more || less != _less) && mounted) {
      setState(() {
        _more = more;
        _less = less;
      });
    }
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
    final onSelected = widget.onSelected;
    final level = TabBarLike.levelOf(context, widget.level);
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
          child: _plain(context, i, level),
        ),
    ];

    // One strip at every width (desktop review 2026-11-13: Reports' 11 tabs
    // wrapped onto two lines). It scrolls sideways and fades at whichever edge
    // has tabs past it; on a desk the mouse drags it (shift+wheel turns it) and
    // the chevron pages it, so no tab is out of reach (Jose 09-29).
    //
    // Underlined words sit apart on one line; a switch's segments share one
    // box, split by a line.
    final gap = level == TabLevel.primary ? 14.0 : 6.0;
    final edge = EzBiziTheme.edge(context);
    List<Widget> laid(List<Widget> items) => items;
    final padding = level == TabLevel.primary
        ? const EdgeInsets.symmetric(horizontal: 16)
        : const EdgeInsets.symmetric(horizontal: 16, vertical: 8);
    Widget rule(Widget row) => level != TabLevel.primary
        ? row
        : DecoratedBox(
            decoration: BoxDecoration(
              border: Border(bottom: BorderSide(color: edge)),
            ),
            child: row,
          );

    if (widget.wrap && MediaQuery.sizeOf(context).width >= 600) {
      return rule(
        Padding(
          padding: padding,
          child: Align(
            alignment: AlignmentDirectional.centerStart,
            child: Wrap(spacing: gap, runSpacing: 8, children: laid(chips)),
          ),
        ),
      );
    }

    // One shape whether or not it fades: swapping a wrapper in and out would
    // rebuild the strip and throw away where it was scrolled to.
    final opaque = Theme.of(context).colorScheme.surface;
    // Phone audit 2026-11-04: a 14 % fade on a dark strip read as nothing, and
    // HR's Payroll sat off the edge unannounced. So the fade is wider, and a
    // chevron says the strip goes on and pages it along when tapped.
    final strip = rule(
      ScrollConfiguration(
        behavior: ScrollConfiguration.of(
          context,
        ).copyWith(dragDevices: {...PointerDeviceKind.values}),
        child: ShaderMask(
          blendMode: BlendMode.dstIn,
          // Only the alpha counts under dstIn: opaque, then none past the edge.
          shaderCallback: (bounds) {
            // A fixed 48 px fade at each edge that has tabs past it.
            final w = bounds.width <= 0 ? 1.0 : bounds.width;
            final f = (48 / w).clamp(0.0, 0.3);
            return LinearGradient(
              colors: [
                _less ? Colors.transparent : opaque,
                opaque,
                opaque,
                _more ? Colors.transparent : opaque,
              ],
              stops: [0, f, 1 - f, 1],
            ).createShader(bounds);
          },
          child: SingleChildScrollView(
            controller: _scroll,
            scrollDirection: Axis.horizontal,
            padding: padding,
            child: Row(
              children: [
                if (_less) const SizedBox.shrink(key: Key('tab-bar-less')),
                for (final chip in laid(chips))
                  Padding(
                    padding: EdgeInsets.only(right: gap),
                    child: chip,
                  ),
                if (_more) const SizedBox.shrink(key: Key('tab-bar-more')),
              ],
            ),
          ),
        ),
      ),
    );
    // Always the Stack, chevron or not: the same shape keeps the scroll.
    return Stack(
      children: [
        // The chevron sits beside the strip, never over a tab: a tab under it
        // could not be tapped (underlined tabs are narrower than the old pills).
        Padding(
          padding: EdgeInsetsDirectional.only(end: _more ? 48 : 0),
          child: strip,
        ),
        if (_more)
          PositionedDirectional(
            end: 0,
            top: 0,
            bottom: 0,
            child: Center(
              child: IconButton(
                key: const Key('tab-bar-chevron'),
                tooltip: 'More tabs',
                icon: const Icon(Icons.chevron_right),
                onPressed: () => _scroll.animateTo(
                  (_scroll.offset + _scroll.position.viewportDimension * 0.7)
                      .clamp(0.0, _scroll.position.maxScrollExtent),
                  duration: const Duration(milliseconds: 200),
                  curve: Curves.easeOut,
                ),
              ),
            ),
          ),
      ],
    );
  }

  /// A module's own tab (a word, underlined when chosen; its icon only when it
  /// carries a status tone) or an inner one (a small pill, filled when chosen).
  /// Still a ChoiceChip, so it hovers, focuses and is found the way every other tab is.
  Widget _plain(BuildContext context, int i, TabLevel level) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final on = i == widget.selected;
    final count = widget.missing?[i] ?? 0;
    final pill = level != TabLevel.primary;
    final tone = widget.tones?[i];
    final word = Text(
      widget.labels[i].$1,
      style: theme.textTheme.labelLarge?.copyWith(
        fontWeight: FontWeight.w600,
        color: pill
            ? (on ? scheme.onPrimaryContainer : scheme.onSurface)
            : (on ? scheme.primary : scheme.onSurfaceVariant),
      ),
    );
    final icon = !pill && tone != null;
    // A bare word stays a Text label, so finders read a tab by its words.
    final text = count == 0 && !icon
        ? word
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (icon) ...[
                Icon(
                  widget.labels[i].$2,
                  size: 16,
                  color: ToneColours.of(context, tone).ink,
                ),
                const SizedBox(width: 6),
              ],
              word,
              if (count > 0) ...[
                const SizedBox(width: 6),
                MissingCount(
                  count,
                  tooltip:
                      '$count ${count == 1 ? 'field' : 'fields'} not filled in',
                ),
              ],
            ],
          );
    // A chip lays itself on the theme's canvas colour, which drew a white box
    // round every word on a tinted strip.
    final chip = Theme(
      data: theme.copyWith(canvasColor: Colors.transparent),
      child: ChoiceChip(
        label: text,
        selected: on,
        showCheckmark: false,
        color: WidgetStatePropertyAll(
          pill && on ? scheme.primaryContainer : Colors.transparent,
        ),
        side: BorderSide.none,
        shape: pill ? const StadiumBorder() : const RoundedRectangleBorder(),
        padding: pill
            ? const EdgeInsets.symmetric(horizontal: 8)
            : const EdgeInsets.symmetric(vertical: 4),
        labelPadding: const EdgeInsets.symmetric(horizontal: 4),
        visualDensity: VisualDensity.compact,
        materialTapTargetSize: pill ? MaterialTapTargetSize.shrinkWrap : null,
        onSelected: (_) => widget.onSelected(i),
      ),
    );
    // Mobile review 2026-11-13, P4: a pill shrink-wrapped to 32 px was too
    // small for a thumb (Scheduling's Availability / Overbooking pills). The
    // pill keeps its look; the area that takes the tap is 40 px tall.
    if (pill) {
      return GestureDetector(
        key: ValueKey('tab-pill-hit-$i'),
        behavior: HitTestBehavior.opaque,
        onTap: () => widget.onSelected(i),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: pillHitHeight),
          child: Center(widthFactor: 1, child: chip),
        ),
      );
    }
    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(
            color: on ? scheme.primary : Colors.transparent,
            width: 2,
          ),
        ),
      ),
      child: chip,
    );
  }
}

/// Which row of tabs a [TabBarLike] is: a module's own, the tabs inside one
/// of those, or a third row inside that.
enum TabLevel { primary, secondary, tertiary }

/// The smallest height a pill takes taps over (mobile review P4).
const double pillHitHeight = 40;
