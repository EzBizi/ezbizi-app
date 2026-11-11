import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// One field of a call set by hand (design 179, Jose 2026-11-03, for Trey):
/// a segment per value, and "Set by X · Automatic ↺" to undo. Saves itself,
/// then [onSaved] so the screen reloads its counts. [current] null selects
/// nothing (the screen does not know the automatic value).
class CallCorrection extends StatefulWidget {
  const CallCorrection({
    super.key,
    required this.api,
    required this.callId,
    required this.field,
    required this.label,
    required this.values,
    required this.current,
    required this.setBy,
    required this.onSaved,
    this.onError,
  });

  /// The two pairs a call can be corrected on, as the server names them.
  static const client = [('new', 'New'), ('existing', 'Existing')];
  static const booking = [('booked', 'Booked'), ('not_booked', 'Not booked')];

  final ApiClient api;
  final String callId;
  final String field;
  final String label;
  final List<(String, String)> values;
  final String? current;
  final Object? setBy;
  final VoidCallback onSaved;
  final void Function(String? message)? onError;

  @override
  State<CallCorrection> createState() => _CallCorrectionState();
}

class _CallCorrectionState extends State<CallCorrection> {
  bool _busy = false;

  Future<void> _save(String? value) async {
    if (_busy) return;
    setState(() => _busy = true);
    final path = '/reports/experimental/calls/${widget.callId}/override';
    final response = value == null
        ? await widget.api.delete(path, {'field': widget.field})
        : await widget.api.put(path, {'field': widget.field, 'value': value});
    if (!mounted) return;
    setState(() => _busy = false);
    if (!response.ok) {
      widget.onError?.call(response.message);
      return;
    }
    widget.onSaved();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final id = widget.callId;
    final by = widget.setBy;
    return Wrap(
      spacing: 8,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 64,
          child: Text(widget.label, style: theme.textTheme.labelMedium),
        ),
        SegmentedButton<String>(
          key: ValueKey('set-${widget.field}-$id'),
          emptySelectionAllowed: true,
          style: const ButtonStyle(visualDensity: VisualDensity.compact),
          segments: [
            for (final (value, text) in widget.values)
              ButtonSegment(value: value, label: Text(text)),
          ],
          selected: {?widget.current},
          onSelectionChanged: _busy
              ? null
              : (picked) {
                  if (picked.isNotEmpty) _save(picked.first);
                },
        ),
        if (by != null) ...[
          Text(
            'Set by ${'$by'.split(' ').first} ·',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          TextButton(
            key: ValueKey('automatic-${widget.field}-$id'),
            onPressed: _busy ? null : () => _save(null),
            child: const Text('Automatic ↺'),
          ),
        ],
      ],
    );
  }
}
