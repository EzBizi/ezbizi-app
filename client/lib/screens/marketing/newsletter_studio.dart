import 'package:flutter/material.dart';

import 'newsletter_blocks.dart';

/// Composer Studio (Rodrigo 2026-11-18, suggestion 01a1ef43): the surfaces
/// the three composer stages share — the warm canvas, the quiet stage tabs,
/// the block tiles with structural previews and the device toggle.
///
/// Colours come from the theme so dark mode keeps its contrast: the canvas
/// is a step darker than the surface, and teal ([ColorScheme.primary]) is
/// spent on actions and selection only. Campaign colours stay inside the
/// email.
class Studio {
  Studio._();

  /// The 8px grid.
  static const double grid = 8;

  /// Library and inspector widths at 1440 (the brief's 220px library).
  static const double libraryWidth = 220;
  static const double inspectorWidth = 288;

  /// Setup and Review: the form and the proof share the width; the proof
  /// pane keeps 440–680px (≈ 480 at 1074, ≈ 600 email at 1440).
  static double proofPaneWidth(double total) =>
      (total * 0.46).clamp(420.0, 680.0);

  /// Below this the stages stack into one column.
  static const double stacked = 900;

  /// Below this Design shows either the library or the inspector beside the
  /// canvas, the other as a labelled drawer.
  static const double roomy = 1280;

  static Color canvas(BuildContext context) =>
      Theme.of(context).colorScheme.surfaceContainerHigh;

  static Color pane(BuildContext context) =>
      Theme.of(context).colorScheme.surfaceContainerLow;

  static Color line(BuildContext context) =>
      Theme.of(context).colorScheme.outlineVariant;

  static Duration motion(BuildContext context) =>
      MediaQuery.maybeDisableAnimationsOf(context) == true
      ? Duration.zero
      : const Duration(milliseconds: 150);
}

/// One stage on the strip: a numbered label, underlined in teal when active.
class StudioStepTab extends StatelessWidget {
  const StudioStepTab({
    super.key,
    required this.number,
    required this.label,
    required this.active,
    required this.onTap,
  });

  final int number;
  final String label;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final ink = active ? scheme.primary : scheme.onSurfaceVariant;
    return Semantics(
      selected: active,
      button: true,
      label: 'Step $number, $label',
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        child: AnimatedContainer(
          duration: Studio.motion(context),
          constraints: const BoxConstraints(minHeight: 44),
          padding: const EdgeInsets.symmetric(horizontal: 4),
          decoration: BoxDecoration(
            border: Border(
              bottom: BorderSide(
                color: active ? scheme.primary : Colors.transparent,
                width: 2,
              ),
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 20,
                height: 20,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: active
                      ? scheme.primaryContainer
                      : scheme.surfaceContainerHighest,
                ),
                child: Text(
                  '$number',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: active
                        ? scheme.onPrimaryContainer
                        : scheme.onSurfaceVariant,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                label,
                style: theme.textTheme.titleSmall?.copyWith(
                  color: ink,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A block in the library: a small structural drawing of what it adds, so a
/// title, picture, button, columns and divider differ at a glance.
class StudioBlockTile extends StatelessWidget {
  const StudioBlockTile({super.key, required this.kind, required this.onTap});

  final BlockKind kind;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Semantics(
      button: true,
      label: 'Add ${kind.label} block',
      excludeSemantics: true,
      child: Material(
        color: scheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
          side: BorderSide(color: Studio.line(context)),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(6),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(
                  height: 40,
                  decoration: BoxDecoration(
                    color: scheme.surfaceContainerHigh,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  padding: const EdgeInsets.symmetric(
                    horizontal: 8,
                    vertical: 6,
                  ),
                  child: _preview(context),
                ),
                const SizedBox(height: 6),
                Text(
                  kind.label,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.labelMedium,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _preview(BuildContext context) {
    final ink = Theme.of(context).colorScheme.onSurfaceVariant;
    Widget bar(double widthFactor, double height) => FractionallySizedBox(
      widthFactor: widthFactor,
      alignment: Alignment.centerLeft,
      child: Container(
        height: height,
        decoration: BoxDecoration(
          color: ink.withValues(alpha: 0.55),
          borderRadius: BorderRadius.circular(1.5),
        ),
      ),
    );
    return switch (kind) {
      BlockKind.heading => Center(
        child: Text(
          'Aa',
          style: TextStyle(
            fontFamily: 'serif',
            fontSize: 20,
            fontWeight: FontWeight.w700,
            color: ink,
          ),
        ),
      ),
      BlockKind.text => Column(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        children: [bar(1, 3), bar(0.9, 3), bar(0.6, 3)],
      ),
      BlockKind.image => Center(
        child: Icon(Icons.image_outlined, size: 22, color: ink),
      ),
      BlockKind.button => Center(
        child: Container(
          width: 44,
          height: 14,
          decoration: BoxDecoration(
            color: ink.withValues(alpha: 0.7),
            borderRadius: BorderRadius.circular(3),
          ),
        ),
      ),
      BlockKind.columns => Row(
        children: [
          for (var i = 0; i < 2; i++) ...[
            if (i == 1) const SizedBox(width: 4),
            Expanded(
              child: Container(
                decoration: BoxDecoration(
                  border: Border.all(color: ink.withValues(alpha: 0.6)),
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
          ],
        ],
      ),
      BlockKind.divider => Center(child: bar(0.5, 1.5)),
      BlockKind.spacer => Center(
        child: Icon(Icons.height, size: 20, color: ink),
      ),
    };
  }
}

/// Desktop · Phone (· Fit) as two or three compact icon toggles.
class StudioDeviceToggle extends StatelessWidget {
  const StudioDeviceToggle({
    super.key,
    required this.value,
    required this.onChanged,
    this.withFit = false,
    this.keyPrefix = 'newsletter-proof',
  });

  /// 'desktop', 'phone' or 'fit'.
  final String value;
  final ValueChanged<String> onChanged;
  final bool withFit;
  final String keyPrefix;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    Widget option(String v, IconData icon, String label) {
      final on = v == value;
      return Tooltip(
        message: label,
        child: Semantics(
          selected: on,
          button: true,
          label: label,
          excludeSemantics: true,
          child: InkWell(
            key: ValueKey('$keyPrefix-$v'),
            borderRadius: BorderRadius.circular(6),
            onTap: () => onChanged(v),
            child: Container(
              width: 36,
              height: 32,
              decoration: BoxDecoration(
                color: on ? scheme.surface : Colors.transparent,
                borderRadius: BorderRadius.circular(6),
                border: on ? Border.all(color: Studio.line(context)) : null,
              ),
              child: Icon(
                icon,
                size: 18,
                color: on ? scheme.primary : scheme.onSurfaceVariant,
              ),
            ),
          ),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(2),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          option('desktop', Icons.desktop_windows_outlined, 'Desktop'),
          option('phone', Icons.phone_iphone, 'Phone'),
          if (withFit) option('fit', Icons.fit_screen_outlined, 'Fit'),
        ],
      ),
    );
  }
}

/// A row in the Setup form: a label column and its value, with a fine
/// divider above (the brief's unboxed rows).
class StudioRow extends StatelessWidget {
  const StudioRow({
    super.key,
    required this.label,
    required this.child,
    this.attention,
  });

  final String label;
  final Widget child;

  /// A short attention state shown beside the label.
  final String? attention;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return LayoutBuilder(
      builder: (context, c) {
        final labelWidget = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: theme.textTheme.titleSmall),
            if (attention != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.error_outline,
                      size: 14,
                      color: theme.colorScheme.error,
                    ),
                    const SizedBox(width: 4),
                    Flexible(
                      child: Text(
                        attention!,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.error,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        );
        return Container(
          padding: const EdgeInsets.symmetric(vertical: 16),
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: Studio.line(context))),
          ),
          child: c.maxWidth < 420
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [labelWidget, const SizedBox(height: 8), child],
                )
              : Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(width: 112, child: labelWidget),
                    const SizedBox(width: 8),
                    Expanded(child: child),
                  ],
                ),
        );
      },
    );
  }
}

/// A quiet "Details" disclosure that builds its children only when open.
class StudioDetails extends StatefulWidget {
  const StudioDetails({
    super.key,
    required this.children,
    this.label = 'Details',
  });

  final List<Widget> children;
  final String label;

  @override
  State<StudioDetails> createState() => _StudioDetailsState();
}

class _StudioDetailsState extends State<StudioDetails> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => setState(() => _open = !_open),
            style: TextButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              minimumSize: const Size(0, 36),
              foregroundColor: theme.colorScheme.onSurfaceVariant,
            ),
            icon: Icon(_open ? Icons.expand_less : Icons.expand_more, size: 18),
            label: Text(
              widget.label,
              semanticsLabel:
                  '${widget.label}, ${_open ? 'expanded' : 'collapsed'}',
            ),
          ),
        ),
        if (_open)
          Padding(
            padding: const EdgeInsets.only(left: 4, top: 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: widget.children,
            ),
          ),
      ],
    );
  }
}
