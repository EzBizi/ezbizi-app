import 'package:flutter/material.dart';

import '../core/theme.dart';

/// The one card every dashboard panel is drawn in.
///
/// ── Why this is a widget and not a convention ────────────────────
///
/// There were two card implementations: one private to the main dashboard, one
/// private to the HR tab. They agreed on 16 points of padding and disagreed on
/// everything else — the HR card had an icon in its header and the main one did
/// not, the HR card had a sentence style for empty panels and the main one used
/// a bare `Text`, and neither had a minimum height, so a panel with one line in
/// it sat as a thin sliver beside a panel with six. The owner's words were that
/// "the widget boxes are unaligned".
///
/// Two implementations of one appearance is not a styling problem that can be
/// fixed by editing both to match; it is a fact with two homes, and the next
/// dashboard would have made three. So the appearance lives here and the
/// screens hand over a title, an icon and a body.
///
/// ── What is standard ─────────────────────────────────────────────
///
///   - [padding] inside, [radius] at the corners, a one-pixel border in
///     [EzBiziTheme.edge] — the same line the shell draws between its regions,
///     rather than the divider colour, which is for rows inside a list.
///   - A header of icon then title, with an optional badge after it. Every
///     panel has an icon; a panel whose key nothing recognises still gets one
///     (see `dashboardPanelIcon`) rather than a header that is a different
///     shape from its neighbours.
///   - [minimumHeight], so a one-line panel is still a card.
///   - An empty panel says something, in [DashboardNote]'s voice.
///
/// Nothing here reads a colour it invented. The border is [EzBiziTheme.edge]
/// and everything else comes off the theme's scheme, so an organization that
/// sets its own (§12) gets cards in it.
class DashboardCard extends StatelessWidget {
  const DashboardCard({
    super.key,
    required this.title,
    required this.icon,
    required this.child,
    this.badge,
    this.controls,
    this.expand = false,
  });

  /// The panel's name, as the server labelled it.
  final String title;

  /// The glyph beside it. Required rather than nullable: an optional icon is
  /// how two cards in the same row end up with headers of different heights.
  final IconData icon;

  /// What the panel says. Laid out below the header, left-aligned, and given
  /// the rest of the card.
  final Widget child;

  /// A state label after the title — "Not built yet", and whatever comes next.
  final Widget? badge;

  /// Controls that belong to the card rather than to the panel: the
  /// presentation switcher, then the move buttons and the drag handle
  /// DashboardGrid supplies. Drawn at the end of the header row, after the
  /// title and its badge.
  ///
  /// Null on a dashboard that cannot be rearranged, and then nothing is drawn —
  /// not a disabled control, which would invite somebody to work out why it
  /// does nothing.
  final Widget? controls;

  /// Fill the parent and give [child] the height under the header.
  ///
  /// Off by default: a card in a list must size to its contents. Turn it on
  /// when the parent is already a bounded slot (an `Expanded` pane) and the
  /// body should use the leftover space rather than grow past it.
  final bool expand;

  /// Padding inside the card, on every side.
  static const double padding = 16;

  /// The corner radius. Matches the app's card theme so a dashboard card and a
  /// card anywhere else are the same object.
  static const double radius = 14;

  /// The shortest a card may be.
  ///
  /// The layout gives every card in a row the height of the tallest, so this
  /// only bites on a row where every panel is short — which is the row that
  /// used to look like a mistake.
  static const double minimumHeight = 176;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final body = Padding(
      padding: const EdgeInsets.all(padding),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 18, color: theme.colorScheme.onSurfaceVariant),
              const SizedBox(width: 8),
              // Everything in this row that carries TEXT can be squeezed,
              // and the only things that cannot are the icon and the
              // controls, which have a size of their own. That is what
              // makes the header safe on a phone (R7): the fixed part is
              // 26 points plus the header controls — presentation, two move
              // buttons and a grip — so a long title or a long badge shortens
              // instead of running off the card.
              //
              // Nesting the title and its badge in a row of their own read
              // better and overflowed a 375-point window by 37 points — a
              // badge that is not flexible has no space to be squeezed out
              // of, so the pair grew past the card rather than shrinking.
              Expanded(
                child: Text(
                  title,
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (badge != null) Flexible(child: badge!),
              ?controls,
            ],
          ),
          const SizedBox(height: 12),
          if (expand) Expanded(child: child) else child,
        ],
      ),
    );

    return Material(
      color: theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(radius),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: expand
          ? SizedBox.expand(child: body)
          : ConstrainedBox(
              constraints: const BoxConstraints(minHeight: minimumHeight),
              child: body,
            ),
    );
  }
}

/// A sentence that says what a number means, or what would make it move.
///
/// Its own widget so every panel says it the same way, and so "a panel with
/// nothing in it explains itself" is something the code does rather than
/// something each panel remembers to do. A bare "0" is indistinguishable from a
/// panel that failed to load.
class DashboardNote extends StatelessWidget {
  const DashboardNote(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Text(
      text,
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );
  }
}

/// The headline figure, with the word that says what it counts.
///
/// The unit is never left off. "1" beside "Headcount by place" could be one
/// person or one place, and the two are a very different message.
class DashboardBigNumber extends StatelessWidget {
  const DashboardBigNumber(this.value, this.unit, {super.key});

  final String value;
  final String unit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      children: [
        Text(value, style: theme.textTheme.headlineMedium),
        const SizedBox(width: 6),
        Flexible(
          child: Text(
            unit,
            style: theme.textTheme.bodyMedium,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }
}

/// A label with a figure at the end of the line.
///
/// Zero is stated in the same weight as any other number rather than greyed
/// out. A place with nobody in it is usually the row somebody opened the
/// dashboard to find.
class DashboardCountRow extends StatelessWidget {
  const DashboardCountRow({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          Expanded(child: Text(label, overflow: TextOverflow.ellipsis)),
          const SizedBox(width: 12),
          Text(value, style: theme.textTheme.titleSmall),
        ],
      ),
    );
  }
}
