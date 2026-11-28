import 'package:flutter/material.dart';

import '../../widgets/search_picker.dart';

/// A phone connection as a picker shows it: its id and a label to read.
/// Read from `/phone/connections` by the Porting, Trust, Voicemail and IVR
/// menus panes.
class PhoneConnectionRef {
  const PhoneConnectionRef(this.id, this.label, [this.defaultVoicemailBoxId]);

  final String id;
  final String label;

  /// The box a line on this account falls back to (Voicemail pane).
  final String? defaultVoicemailBoxId;

  static List<PhoneConnectionRef> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        PhoneConnectionRef(
          row['id'].toString(),
          (row['scope_name'] ?? row['label'] ?? row['account_sid'] ?? 'Phone account').toString(),
          row['default_voicemail_box_id'] as String?,
        ),
    ];
  }
}

/// A ring group as a picker shows it: its id and name. Read from
/// `/phone/ring-groups` by the IVR menus and Numbers panes.
@immutable
class RingGroupRef {
  const RingGroupRef({required this.id, required this.name});

  final String id;
  final String name;

  static List<RingGroupRef> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => RingGroupRef(
            id: r['id'].toString(),
            name: r['name']?.toString() ?? 'Group',
          ),
        )
        .toList(growable: false);
  }
}

/// Which phone account a pane is showing, when there is more than one
/// (audit 11-27: Trust and Tags silently used an arbitrary account).
class PhoneAccountPicker extends StatelessWidget {
  const PhoneAccountPicker({
    super.key,
    required this.accounts,
    required this.selected,
    required this.onChanged,
  });

  final List<PhoneConnectionRef> accounts;
  final String? selected;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    if (accounts.length < 2) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: SearchPickerField<String>(
        key: const ValueKey('phone-account-picker'),
        value: selected,
        label: 'Phone account',
        border: const OutlineInputBorder(),
        options: [
          for (final a in accounts) SearchOption(value: a.id, label: a.label),
        ],
        onChanged: (id) {
          if (id != null) onChanged(id);
        },
      ),
    );
  }
}

/// A Remove that asks on the button itself: the first tap turns it into
/// "Tap again to remove" (audit 11-27: one click silently detached every
/// line using it). No dialog — the house rule.
class TwoTapRemove extends StatefulWidget {
  const TwoTapRemove({
    super.key,
    required this.onRemove,
    this.enabled = true,
    this.icon = false,
  });

  final VoidCallback onRemove;
  final bool enabled;

  /// A delete icon at rest, for rows with no room for words.
  final bool icon;

  @override
  State<TwoTapRemove> createState() => _TwoTapRemoveState();
}

class _TwoTapRemoveState extends State<TwoTapRemove> {
  bool _armed = false;

  @override
  Widget build(BuildContext context) {
    final onPressed = !widget.enabled
        ? null
        : () {
            if (_armed) {
              setState(() => _armed = false);
              widget.onRemove();
            } else {
              setState(() => _armed = true);
            }
          };

    if (widget.icon) {
      return IconButton(
        tooltip: _armed ? 'Tap again to delete' : 'Delete',
        icon: Icon(_armed ? Icons.delete_forever : Icons.delete_outline),
        color: _armed ? Theme.of(context).colorScheme.error : null,
        onPressed: onPressed,
      );
    }

    return TextButton(
      onPressed: onPressed,
      child: Text(_armed ? 'Tap again to remove' : 'Remove'),
    );
  }
}
