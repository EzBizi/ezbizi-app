/// ISO-8601 from the server, shown in the reader's own zone. Formatting is
/// deliberately plain — a wrong "2 hours ago" is worse than an exact stamp.
///
/// [fallback] is what belongs on screen when there is no usable time, and it is
/// never the same word twice: a job with no date reads "Not scheduled", an
/// account that has never signed in reads "never". The parsing is the shared
/// part; the wording is the caller's.
String formatStamp(Object? iso, String fallback) {
  final at = iso is String ? DateTime.tryParse(iso)?.toLocal() : null;

  if (at == null) return fallback;

  String two(int n) => n.toString().padLeft(2, '0');

  return '${at.year}-${two(at.month)}-${two(at.day)} ${two(at.hour)}:${two(at.minute)}';
}
