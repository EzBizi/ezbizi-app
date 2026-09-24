import 'package:flutter/material.dart';

import '../core/theme.dart';

/// A status, in the colour of what it means.
///
/// Not [Tag]: a tag is a grey label beside a name and carries its own leading
/// gap for sitting there. This is the state of the thing itself, in its own
/// column, and its colour is the reading — see [Tone].
class StatusPill extends StatelessWidget {
  const StatusPill(this.label, {super.key, this.tone = Tone.neutral});

  final String label;
  final Tone tone;

  @override
  Widget build(BuildContext context) {
    final colours = ToneColours.of(context, tone);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 2),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
          color: colours.ink,
        ),
      ),
    );
  }
}

/// The small dot a filter chip wears, so the chips double as the legend for
/// the pills below them.
class ToneDot extends StatelessWidget {
  const ToneDot(this.tone, {super.key});

  final Tone tone;

  @override
  Widget build(BuildContext context) => Container(
    width: 8,
    height: 8,
    decoration: BoxDecoration(
      color: ToneColours.of(context, tone).ink,
      shape: BoxShape.circle,
    ),
  );
}
