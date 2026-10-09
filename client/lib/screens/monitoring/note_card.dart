import 'package:flutter/material.dart';

/// A plain sentence in a card — what the monitoring tabs show in place of
/// content they cannot draw (no permission, nothing selected, no devices).
class NoteCard extends StatelessWidget {
  const NoteCard(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
      ),
    );
  }
}
