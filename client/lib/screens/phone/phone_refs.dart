import 'package:flutter/material.dart';

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
      child: DropdownButton<String>(
        key: const ValueKey('phone-account-picker'),
        value: selected,
        items: [
          for (final a in accounts)
            DropdownMenuItem(value: a.id, child: Text(a.label)),
        ],
        onChanged: (id) => id == null ? null : onChanged(id),
      ),
    );
  }
}

/// Ask before removing something lines may be using (audit 11-27: one click
/// silently detached every line that used it).
Future<bool> confirmPhoneRemove(BuildContext context, String what) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text('Remove $what?'),
      content: const Text(
        'Any line using it stops using it and falls back to its default.',
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text('Cancel'),
        ),
        TextButton(
          key: const ValueKey('confirm-remove'),
          onPressed: () => Navigator.pop(context, true),
          child: const Text('Remove'),
        ),
      ],
    ),
  );

  return ok == true;
}
