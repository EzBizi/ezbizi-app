import 'dart:async';

import 'package:flutter/material.dart';

/// Ask for one short name, in place.
///
/// This replaces two `AlertDialog`s — the folder name and the new task title.
/// `StartUp.md` is explicit that there are no pop-ups: a new screen or a
/// right-side panel, and "Add a modal" is on the never-do list. Both dialogs
/// predate anyone checking; neither was a destructive confirm, so neither
/// collapses into an inline confirm row. They need somewhere to type, and this
/// is that somewhere.
///
/// The callers `await` a `String?` the way they awaited `showDialog`, because
/// rewriting three async flows to be state-driven would have been a much larger
/// change than the rule required. [NameEntryRequest] bridges the two: the host
/// holds one in state, renders this panel while it is there, and completes its
/// future on submit or cancel. Same call sites, no pop-up.
class NameEntryPanel extends StatefulWidget {
  const NameEntryPanel({
    super.key,
    required this.request,
    this.fieldKey,
  });

  final NameEntryRequest request;

  /// Lets a host keep an existing widget key on the field, so tests and
  /// automation that already reach for it do not break.
  final Key? fieldKey;

  @override
  State<NameEntryPanel> createState() => _NameEntryPanelState();
}

class _NameEntryPanelState extends State<NameEntryPanel> {
  late final TextEditingController _controller = TextEditingController(
    text: widget.request.initial,
  );

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    final value = _controller.text.trim();
    if (value.isEmpty) return;
    widget.request.submit(value);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Semantics(
      container: true,
      label: widget.request.title,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    widget.request.title,
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                IconButton(
                  onPressed: widget.request.cancel,
                  icon: const Icon(Icons.close),
                  tooltip: 'Cancel',
                ),
              ],
            ),
            const SizedBox(height: 8),
            TextField(
              key: widget.fieldKey,
              controller: _controller,
              autofocus: true,
              textInputAction: TextInputAction.done,
              decoration: InputDecoration(labelText: widget.request.label),
              onSubmitted: (_) => _submit(),
            ),
            const SizedBox(height: 12),
            // Wraps rather than overflowing: at 375px the two buttons and a
            // long submit label do not fit on one line.
            Wrap(
              alignment: WrapAlignment.end,
              spacing: 8,
              runSpacing: 8,
              children: [
                TextButton(
                  onPressed: widget.request.cancel,
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: _submit,
                  child: Text(widget.request.submitLabel),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// One pending name request, held in a host's state.
///
/// [future] completes with the trimmed name, or null if the person cancelled —
/// the same contract `showDialog<String>` had.
class NameEntryRequest {
  NameEntryRequest({
    required this.title,
    required this.label,
    required this.submitLabel,
    this.initial = '',
  });

  final String title;
  final String label;
  final String submitLabel;
  final String initial;

  final Completer<String?> _completer = Completer<String?>();

  Future<String?> get future => _completer.future;

  void submit(String value) {
    if (!_completer.isCompleted) _completer.complete(value);
  }

  void cancel() {
    if (!_completer.isCompleted) _completer.complete(null);
  }
}
