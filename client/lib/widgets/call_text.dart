import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/theme.dart';

/// A call's AI score band (Jose, 2026-11-02): 8 and up, 5 to 7, below.
Tone scoreTone(num score) => score >= 8
    ? Tone.ok
    : score >= 5
    ? Tone.caution
    : Tone.bad;

/// "Did well", "To improve" and the like: bold, in the tone's ink.
class ToneHeading extends StatelessWidget {
  const ToneHeading(this.label, this.tone, {super.key});

  final String label;
  final Tone tone;

  @override
  Widget build(BuildContext context) => Text(
    label,
    style: Theme.of(context).textTheme.labelMedium?.copyWith(
      color: ToneColours.of(context, tone).ink,
      fontWeight: FontWeight.w700,
    ),
  );
}

/// The small copy icon, as Leads' message copy: copies and says so.
class CopyIcon extends StatelessWidget {
  const CopyIcon(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: 'Copy',
    child: InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: () {
        // ponytail: not awaited, so the snackbar never waits on the platform.
        unawaited(Clipboard.setData(ClipboardData(text: text)));
        ScaffoldMessenger.maybeOf(
          context,
        )?.showSnackBar(const SnackBar(content: Text('Copied')));
      },
      child: Padding(
        padding: const EdgeInsets.only(left: 6),
        child: Icon(
          Icons.copy_outlined,
          size: 14,
          semanticLabel: 'Copy',
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      ),
    ),
  );
}

/// Selectable text with a copy icon beside it; [copy] when what is copied
/// differs from what is shown (a formatted number copies the dialable one).
class Copyable extends StatelessWidget {
  const Copyable(this.text, {super.key, this.style, this.copy});

  final String text;
  final TextStyle? style;
  final String? copy;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Flexible(
        child: SelectableText(
          text,
          style: style ?? Theme.of(context).textTheme.bodySmall,
        ),
      ),
      CopyIcon(copy ?? text),
    ],
  );
}

/// A transcript Grok divided by speaker, each label in its own colour
/// (Jose, 2026-11-02): Dispatcher primary, Customer ok ink, a machine muted
/// italic. The words keep the normal ink. Selectable, one copy icon.
class CallTranscriptText extends StatelessWidget {
  const CallTranscriptText(this.text, {super.key, this.style});

  final String text;
  final TextStyle? style;

  static final _label = RegExp(r'^([A-Za-z][A-Za-z ]{0,19}): ');

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    TextStyle labelStyle(String who) => switch (who) {
      'Dispatcher' => TextStyle(
        color: theme.colorScheme.primary,
        fontWeight: FontWeight.w700,
      ),
      'Customer' => TextStyle(
        color: ToneColours.of(context, Tone.ok).ink,
        fontWeight: FontWeight.w700,
      ),
      _ => TextStyle(
        color: theme.colorScheme.onSurfaceVariant,
        fontStyle: FontStyle.italic,
      ),
    };
    final lines = text.split('\n');

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: SelectableText.rich(
            TextSpan(
              children: [
                for (var i = 0; i < lines.length; i++) ...[
                  if (_label.firstMatch(lines[i]) case final m?) ...[
                    TextSpan(text: m.group(0), style: labelStyle(m.group(1)!)),
                    TextSpan(text: lines[i].substring(m.end)),
                  ] else
                    TextSpan(text: lines[i]),
                  if (i < lines.length - 1) const TextSpan(text: '\n'),
                ],
              ],
            ),
            style: style ?? theme.textTheme.bodySmall,
          ),
        ),
        CopyIcon(text),
      ],
    );
  }
}
