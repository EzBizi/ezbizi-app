import 'package:flutter/foundation.dart';

/// A phone connection as a picker shows it: its id and a label to read.
/// Read from `/phone/connections` by the Porting and Trust panes.
class PhoneConnectionRef {
  const PhoneConnectionRef(this.id, this.label);

  final String id;
  final String label;

  static List<PhoneConnectionRef> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        PhoneConnectionRef(
          row['id'].toString(),
          (row['label'] ?? row['account_sid'] ?? 'Phone account').toString(),
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
