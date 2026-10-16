/// Where the device is, for a clock-in or clock-out, only when a clock-in
/// zone applies to this person (`needs_location` on the time-clock block).
/// Nobody else is ever asked for location. An empty body when there is no
/// fix: the server then answers with the sentence saying location is needed.
library;

import 'field_tracker.dart';

Future<Map<String, dynamic>> punchPosition(bool needed) async {
  if (!needed) return const {};
  final fix = await FieldTracker.currentPosition();
  if (fix == null) return const {};

  return {
    'latitude': fix.latitude,
    'longitude': fix.longitude,
    'accuracy': fix.accuracy,
  };
}
