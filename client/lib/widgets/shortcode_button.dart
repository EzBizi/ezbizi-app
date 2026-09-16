import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// A shortcode you click rather than select.
///
/// It shows the code itself rather than a copy icon beside a label, because the
/// thing being copied is short, meaningful, and the only reason the control
/// exists. Confirming in place — the code becomes "Copied" for a moment — keeps
/// §6: a snackbar for this would be a notification about the thing you are
/// looking at.
///
/// Shared rather than copied. It lived inside the SEO tab until the page and
/// template screens needed it too, and a second hand-rolled copy button is how
/// two screens end up disagreeing about what "copied" looks like.
class ShortcodeButton extends StatefulWidget {
  const ShortcodeButton({super.key, required this.code, this.label});

  final String code;

  /// What to show instead of the code itself, for something too long to wear
  /// as a label — the whole script tag, say.
  final String? label;

  @override
  State<ShortcodeButton> createState() => _ShortcodeButtonState();
}

class _ShortcodeButtonState extends State<ShortcodeButton> {
  bool _copied = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return OutlinedButton.icon(
      onPressed: () async {
        await Clipboard.setData(ClipboardData(text: widget.code));

        if (!mounted) return;

        setState(() => _copied = true);
        await Future<void>.delayed(const Duration(milliseconds: 1200));

        if (mounted) setState(() => _copied = false);
      },
      icon: Icon(_copied ? Icons.check : Icons.copy_outlined, size: 15),
      label: Text(
        _copied ? 'Copied' : (widget.label ?? widget.code),
        style: TextStyle(
          fontFamily: widget.label == null ? 'monospace' : null,
          fontSize: 12,
        ),
      ),
      style: OutlinedButton.styleFrom(
        foregroundColor:
            _copied ? theme.colorScheme.primary : theme.colorScheme.outline,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
      ),
    );
  }
}

/// A field with its shortcode beside it, or beneath it when there is no room.
///
/// 460 is where a field row plus the chip stops fitting without the chip
/// wrapping mid-code, which reads as a broken control rather than a narrow one.
Widget withShortcode(Widget input, String code) {
  final chip = ShortcodeButton(code: code);

  return LayoutBuilder(
    builder: (context, constraints) => constraints.maxWidth >= 460
        ? Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: input),
              const SizedBox(width: 8),
              Padding(padding: const EdgeInsets.only(top: 2), child: chip),
            ],
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [input, const SizedBox(height: 6), chip],
          ),
  );
}
