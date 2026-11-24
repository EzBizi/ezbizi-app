import 'package:flutter/material.dart';
import '../core/session.dart';

/// How this person wants the app to look (§2.1 category 6).
///
/// Sits beside the two-factor card under "Your account", above the
/// organization's settings — because these belong to the person and everything
/// below belongs to whoever administers the tenant. §2.1 is explicit that the
/// two categories must not be merged, and the screen should say so by its shape
/// before anybody reads a word.
///
/// Changes apply immediately and optimistically. A theme that flickers back to
/// the old one for half a second while a round trip completes looks broken; the
/// session puts the truth back if the server refuses the value.
class AppearanceCard extends StatelessWidget {
  const AppearanceCard({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    final me = session.me!;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Appearance', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              'Yours alone — nobody else in your organization sees these.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),

            const SizedBox(height: 18),
            _row(
              context,
              label: 'Theme',
              // Light and dark only (Jose 11-24: no "match my device").
              options: const [
                ('light', 'Light'),
                ('dark', 'Dark'),
                // Named by what changes rather than "Mixed 1" and "Mixed 2".
                // Somebody choosing between them is choosing which half is
                // dark, so that is what the label says.
                ('mixed_dark_nav', 'Dark sidebar'),
                ('mixed_light_nav', 'Light sidebar'),
              ],
              selected: me.theme,
              onChanged: (v) => session.setPreference('appearance.theme', v),
            ),

            const SizedBox(height: 16),
            _row(
              context,
              label: 'Sidebar',
              options: const [
                ('list', 'List'),
                ('blocks', 'Blocks'),
              ],
              selected: me.navigationStyle,
              onChanged: (v) => session.setPreference('navigation.style', v),
              help: 'A list puts the icon and label side by side. Blocks stack them '
                  'and take less width.',
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(
    BuildContext context, {
    required String label,
    required List<(String, String)> options,
    required String selected,
    required ValueChanged<String> onChanged,
    String? help,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 8),
        // Wrap rather than Row: on a narrow window a SegmentedButton with three
        // options overflows, and an overflowing control is one you cannot use.
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: options
              .map((o) => ChoiceChip(
                    label: Text(o.$2),
                    selected: selected == o.$1,
                    onSelected: (_) => onChanged(o.$1),
                  ))
              .toList(),
        ),
        if (help != null) ...[
          const SizedBox(height: 6),
          Text(help, style: Theme.of(context).textTheme.bodyMedium),
        ],
      ],
    );
  }
}
