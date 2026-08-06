import 'package:flutter/material.dart';

/// A small state label beside a name — "Demo", "Read only", "Disabled".
class Tag extends StatelessWidget {
  const Tag(this.label, {super.key, this.warn = false});

  final String label;

  /// Something the reader needs to notice, not just know.
  final bool warn;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(left: 8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: (warn ? scheme.errorContainer : scheme.surfaceContainerHighest)
              .withValues(alpha: 0.6),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: warn ? scheme.error : null,
          ),
        ),
      ),
    );
  }
}
