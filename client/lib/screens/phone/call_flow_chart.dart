/// What is happening on a phone line right now, over the shared flow chart.
///
/// The graph, the layering and the canvas moved to `widgets/flow_chart.dart`
/// on 2026-09-17, when Jose asked for the same chart in the Inspection
/// Builder. **This file re-exports them**, so every `call_flow_chart.dart`
/// import in Phone and in its tests still names one place and still gets
/// `FlowNode`, `layOutFlow` and the rest.
///
/// What stayed is the part that is only true of a telephone: how many callers
/// are holding in a queue this second.
library;

import '../../widgets/flow_chart.dart';

export '../../widgets/flow_chart.dart';

/// The node a call flow begins at.
///
/// Phone's, and it lives here rather than on [CallFlowGraph] because it is a
/// fact about telephone calls: the server guarantees exactly one entry node
/// and that it is always `caller`, which is what makes Jose's "Caller ----
/// (what happens)" literal.
///
/// Named at every call site rather than defaulted. A default is inherited by
/// whoever forgets, and the one who forgets is usually the module that owns
/// the word.
const String phoneEntryNode = 'caller';

/// How many callers are holding in one queue, and how long they have waited.
class QueueLive {
  const QueueLive({required this.waiting, required this.averageWaitSeconds});

  final int waiting;
  final int averageWaitSeconds;

  static QueueLive fromJson(Map<String, dynamic> json) => QueueLive(
    waiting: (json['waiting'] as num?)?.toInt() ?? 0,
    averageWaitSeconds: (json['average_wait_seconds'] as num?)?.toInt() ?? 0,
  );
}

/// What is happening on this line right now (Jose: *"see Live Calls flowing
/// through the Node as well"*).
///
/// Exact where callers actually pile up and silent everywhere else, which is
/// Phone's measurement rather than a design compromise: a call in the menu, a
/// call ringing a group and a call holding are all `status = ringing` on the
/// row, and they declined to add a per-step column on purpose — the existing
/// status already goes stale when a final callback never arrives. Their
/// sentence: *"a tile reading 2 calls here about a call that ended on Tuesday
/// is worse than a tile reading nothing."*
class CallFlowLive {
  const CallFlowLive({
    required this.onLine,
    required this.queues,
    required this.queuesKnown,
  });

  /// Ringing or connected on this line now, with a 30-minute ceiling on the
  /// server: a call whose final callback never arrives would otherwise sit at
  /// `ringing` for ever, and the one number on this screen that means "right
  /// now" would be the one that is never right.
  final int onLine;

  /// Keyed by RING GROUP id, which is what [FlowNode.ref] holds.
  final Map<String, QueueLive> queues;

  /// False when Twilio could not be asked.
  ///
  /// It is a flag rather than an empty map because **an empty map is also what
  /// a quiet afternoon looks like**. Phone found their first version returned
  /// empty on failure — a row of confident zeroes at the exact moment it has
  /// no idea.
  final bool queuesKnown;

  /// The count for a node, or null when there is nothing honest to draw.
  ///
  /// All three rules in one place, because they are easy to get individually
  /// right and collectively wrong:
  ///
  ///   * not a queue node — null; no other kind has a count at all;
  ///   * we could not ask — null, NOT zero;
  ///   * asked, and this queue is absent — **zero**. A Twilio queue resource
  ///     exists only once it has been used, so a group that has never held
  ///     anybody has no resource. Absent means empty, and the tile says 0
  ///     rather than dashes.
  QueueLive? forNode(FlowNode node) {
    if (!queuesKnown) return null;
    if (node.ref.isEmpty) return null;

    return queues[node.ref] ??
        const QueueLive(waiting: 0, averageWaitSeconds: 0);
  }

  static CallFlowLive? fromJson(Map<String, dynamic>? json) {
    if (json == null) return null;

    final raw = json['queues'];

    return CallFlowLive(
      onLine: (json['on_line'] as num?)?.toInt() ?? 0,
      queuesKnown: json['queues_known'] as bool? ?? false,
      queues: {
        if (raw is Map)
          for (final entry in raw.entries)
            if (entry.value is Map<String, dynamic>)
              '${entry.key}': QueueLive.fromJson(
                entry.value as Map<String, dynamic>,
              ),
      },
    );
  }
}
