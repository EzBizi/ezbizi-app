import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// The small copy icon beside a value: copies [text] and says "[what] copied".
class CopyButton extends StatelessWidget {
  const CopyButton(this.text, {super.key, required this.what});

  final String text;
  final String what;

  @override
  Widget build(BuildContext context) => InkWell(
    borderRadius: BorderRadius.circular(8),
    onTap: () async {
      await Clipboard.setData(ClipboardData(text: text));
      if (!context.mounted) return;
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text('$what copied')));
    },
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      child: Icon(
        Icons.copy_outlined,
        size: 14,
        semanticLabel: 'Copy ${what.toLowerCase()}',
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
    ),
  );
}
