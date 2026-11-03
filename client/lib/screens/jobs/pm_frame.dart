import 'package:flutter/material.dart';

import 'pm_stages.dart';

/// What the Project Management screen hands every tab's frame: the way to
/// the Rules tab at a tab's own section, and the way to the scope's
/// Settings card. Absent (a tab pumped on its own): no link is drawn.
class PmFrameLinks extends InheritedWidget {
  const PmFrameLinks({
    super.key,
    required super.child,
    this.onHow,
    this.onChangeScope,
  });

  /// "How this is measured ›": the Rules tab, scrolled to [tab]'s section.
  final void Function(String tab)? onHow;

  /// The scope bar's Change: Settings, at the scope card.
  final VoidCallback? onChangeScope;

  static PmFrameLinks? of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<PmFrameLinks>();

  @override
  bool updateShouldNotify(PmFrameLinks old) =>
      old.onHow != onHow || old.onChangeScope != onChangeScope;
}

/// The one frame every Project Management tab draws, top to bottom (design
/// 144, option 1; phone 4), under the screen's tab strip:
///
/// 1. one sentence saying what the tab is for, and "How this is measured ›";
/// 2. the scope bar: which jobs are counted, where that is set, Change;
/// 3. the filter bar: search first, the place second, then the tab's own
///    filters. On a phone the place and the tab's filters sit behind a
///    Filters button, so the content starts on the first screen.
///
/// Then the tab's content, which owns its own scrolling. The gutter is
/// [gutter] on every tab; content lines up by using it too.
class PmFrame extends StatefulWidget {
  const PmFrame({
    super.key,
    required this.tab,
    required this.purpose,
    required this.search,
    required this.child,
    this.scope = const PmScope(),
    this.scopeText,
    this.place,
    this.filters = const [],
    this.below,
  });

  /// The tab's key, for "How this is measured ›" and for tests.
  final String tab;
  final String purpose;
  final PmScope scope;

  /// Said in the scope bar instead of the scope, with no Change: a tab whose
  /// rows are not Project Management's jobs.
  final String? scopeText;

  final Widget search;
  final Widget? place;
  final List<Widget> filters;

  /// Drawn under the filter bar, always shown: the Jobs tab's flow and drill
  /// chip.
  final Widget? below;

  final Widget child;

  /// Under this width the frame is a phone's.
  static const phoneBelow = 600.0;

  static double gutter(double width) => width < phoneBelow ? 16 : 24;

  @override
  State<PmFrame> createState() => _PmFrameState();
}

class _PmFrameState extends State<PmFrame> {
  bool _filtersOpen = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final links = PmFrameLinks.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final link = muted?.copyWith(
      color: scheme.primary,
      fontWeight: FontWeight.w700,
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < PmFrame.phoneBelow;
        final pad = PmFrame.gutter(constraints.maxWidth);
        final extra = [?widget.place, ...widget.filters];

        final purpose = Text.rich(
          TextSpan(
            children: [
              TextSpan(text: widget.purpose),
              if (links?.onHow != null && widget.tab != 'rules') ...[
                const TextSpan(text: '  '),
                WidgetSpan(
                  alignment: PlaceholderAlignment.baseline,
                  baseline: TextBaseline.alphabetic,
                  child: InkWell(
                    key: ValueKey('pm-frame-how-${widget.tab}'),
                    borderRadius: BorderRadius.circular(4),
                    onTap: () => links!.onHow!(widget.tab),
                    child: Text('How this is measured ›', style: link),
                  ),
                ),
              ],
            ],
          ),
          key: const ValueKey('pm-frame-purpose'),
          style: theme.textTheme.bodyMedium,
        );

        final scope = PmScopeLine(
          scope: widget.scope,
          text: widget.scopeText,
          onChange: widget.scopeText == null ? links?.onChangeScope : null,
        );

        final Widget bar;
        if (phone) {
          bar = Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(child: widget.search),
                  if (extra.isNotEmpty) ...[
                    const SizedBox(width: 8),
                    OutlinedButton.icon(
                      key: const ValueKey('pm-frame-filters-button'),
                      onPressed: () =>
                          setState(() => _filtersOpen = !_filtersOpen),
                      icon: Icon(
                        _filtersOpen ? Icons.expand_less : Icons.tune,
                        size: 18,
                      ),
                      label: const Text('Filters'),
                    ),
                  ],
                ],
              ),
              if (_filtersOpen)
                for (final w in extra) ...[const SizedBox(height: 8), w],
            ],
          );
        } else {
          bar = Wrap(
            spacing: 12,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.start,
            children: [
              SizedBox(width: 300, child: widget.search),
              if (widget.place != null)
                ConstrainedBox(
                  constraints: BoxConstraints(
                    maxWidth: (constraints.maxWidth - pad * 2).clamp(
                      120.0,
                      320.0,
                    ),
                  ),
                  child: widget.place,
                ),
              ...widget.filters,
            ],
          );
        }

        return Column(
          key: ValueKey('pm-frame-${widget.tab}'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 12, pad, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  purpose,
                  const SizedBox(height: 4),
                  scope,
                  const SizedBox(height: 10),
                  KeyedSubtree(
                    key: const ValueKey('pm-frame-filters'),
                    child: bar,
                  ),
                  if (widget.below != null) ...[
                    const SizedBox(height: 10),
                    widget.below!,
                  ],
                ],
              ),
            ),
            const SizedBox(height: 12),
            Expanded(child: widget.child),
          ],
        );
      },
    );
  }
}

/// ⓘ: the rule a number was counted by, on hover or tap.
class PmInfo extends StatelessWidget {
  const PmInfo(this.message, {super.key, this.size = 16});

  final String message;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: message,
      triggerMode: TooltipTriggerMode.tap,
      showDuration: const Duration(seconds: 8),
      child: Semantics(
        label: 'How this is counted',
        child: Icon(
          Icons.info_outline,
          size: size,
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}
