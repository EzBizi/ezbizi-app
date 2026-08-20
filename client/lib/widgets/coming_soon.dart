import 'package:flutter/material.dart';

import 'tag.dart';

/// A destination that is granted and not built.
///
/// A blank body reads as a broken tab or a missing permission. This is the
/// same answer Newsletter and the HR "not built yet" panels already give:
/// the name, the state, and a sentence about what the tab will be.
class ComingSoonScreen extends StatelessWidget {
  const ComingSoonScreen({
    super.key,
    required this.title,
    required this.what,
  });

  final String title;
  final String what;

  static const double _readable = 760;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: _readable),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(title, style: theme.textTheme.headlineSmall),
                    const Tag('Coming soon', warn: true),
                  ],
                ),
                const SizedBox(height: 8),
                Text(what, style: theme.textTheme.bodyMedium),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
