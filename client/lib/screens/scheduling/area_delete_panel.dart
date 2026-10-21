import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

/// Deleting a service area asks where its ZIPs go (UI queue 125, Jose's pick
/// 1): move them to another area of the same branch, or leave them
/// unassigned. `DELETE /scheduling/areas/{id}[?move_zips_to=]` answers
/// `{moved, unassigned, moved_to}`; the panel pops with that, and the caller
/// says it in a SnackBar through [deleteResultLine].
///
/// A side pane, never a dialog. Delete stays off until a choice is made, so
/// one stray click cannot drop an area's ZIPs anywhere.
class AreaDeletePanel extends StatefulWidget {
  const AreaDeletePanel({
    super.key,
    required this.api,
    required this.areaId,
    required this.areaName,
    required this.zipCount,
    required this.siblings,
  });

  final ApiClient api;
  final String areaId;
  final String areaName;
  final int zipCount;

  /// The other areas of the same branch, `{id, name}`: where ZIPs may move.
  final List<Map<String, dynamic>> siblings;

  @override
  State<AreaDeletePanel> createState() => _AreaDeletePanelState();
}

/// "18 moved to East", "18 now unassigned", or "Deleted Downtown."
String deleteResultLine(String areaName, Map<String, dynamic>? result) {
  final moved = (result?['moved'] as num?)?.toInt() ?? 0;
  final unassigned = (result?['unassigned'] as num?)?.toInt() ?? 0;
  final to = (result?['moved_to'] as Map?)?['name'] as String?;
  if (moved > 0) return '$moved moved to ${to ?? 'the other area'}';
  if (unassigned > 0) return '$unassigned now unassigned';
  return 'Deleted $areaName.';
}

class _AreaDeletePanelState extends State<AreaDeletePanel> {
  /// 'move' or 'leave'; null until somebody picks.
  String? _choice;
  String? _to;
  bool _busy = false;
  String? _error;

  bool get _ready =>
      widget.zipCount == 0 ||
      _choice == 'leave' ||
      (_choice == 'move' && _to != null);

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.delete(
      '/scheduling/areas/${widget.areaId}',
      _choice == 'move' && _to != null ? {'move_zips_to': _to!} : null,
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }
    Navigator.of(context).pop(
      response.body['data'] as Map<String, dynamic>? ?? <String, dynamic>{},
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final n = widget.zipCount;
    final name = widget.areaName;

    return SidePanel(
      title: 'Delete $name',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('area-delete-go'),
          onPressed: _ready && !_busy ? _delete : null,
          style: FilledButton.styleFrom(
            backgroundColor: theme.colorScheme.error,
            foregroundColor: theme.colorScheme.onError,
          ),
          child: Text(_busy ? 'Deleting…' : 'Delete'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            n == 0
                ? '$name has no ZIPs.'
                : '$name has $n ${n == 1 ? 'ZIP' : 'ZIPs'}. Where should '
                      '${n == 1 ? 'it' : 'they'} go?',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          Text(
            'Its week goes with it, and it cannot be undone. Anything already '
            'booked in $name is not touched.',
            style: theme.textTheme.bodySmall,
          ),
          if (n > 0) ...[
            const SizedBox(height: 12),
            RadioGroup<String>(
              groupValue: _choice,
              onChanged: (v) {
                if (_busy) return;
                setState(() => _choice = v);
              },
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  RadioListTile<String>(
                    key: const ValueKey('area-delete-move'),
                    value: 'move',
                    enabled: widget.siblings.isNotEmpty,
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Move them to'),
                    subtitle: widget.siblings.isEmpty
                        ? const Text('No other area in this branch.')
                        : null,
                  ),
                  if (_choice == 'move')
                    Padding(
                      padding: const EdgeInsets.only(left: 40, bottom: 8),
                      child: SearchPickerField<String>(
                        key: const ValueKey('area-delete-to'),
                        value: _to,
                        label: 'Area',
                        options: [
                          for (final a in widget.siblings)
                            SearchOption<String>(
                              value: a['id'] as String,
                              label: a['name'] as String? ?? '',
                            ),
                        ],
                        onChanged: (v) => setState(() => _to = v),
                      ),
                    ),
                  RadioListTile<String>(
                    key: const ValueKey('area-delete-leave'),
                    value: 'leave',
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Leave them unassigned'),
                  ),
                ],
              ),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}
