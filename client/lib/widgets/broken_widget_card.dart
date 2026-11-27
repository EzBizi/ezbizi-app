import 'package:flutter/material.dart';

/// What a widget that threw while building draws instead of itself: a small
/// card saying so, so one bad view never turns the whole app grey or black
/// (phone review 2026-11-13, item 1: Reports › Scoring blanked the app).
///
/// No Theme lookups: it can be built under a context with no MaterialApp
/// above it, so it brings its own Directionality and plain colours.
class BrokenWidgetCard extends StatelessWidget {
  const BrokenWidgetCard({super.key, required this.details});

  final FlutterErrorDetails details;

  static void install() {
    ErrorWidget.builder = (details) => BrokenWidgetCard(details: details);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Material(
        type: MaterialType.transparency,
        child: Container(
          key: const Key('broken-widget-card'),
          margin: const EdgeInsets.all(8),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: scheme.errorContainer,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            'This part of the screen could not be drawn. '
            'The problem has been reported.',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: scheme.onErrorContainer,
            ),
          ),
        ),
      ),
    );
  }
}
