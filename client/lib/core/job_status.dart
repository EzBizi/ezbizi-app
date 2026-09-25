import 'theme.dart';

/// One job status: the value jobs store, and the name people see.
class JobStatus {
  const JobStatus({required this.value, required this.label, this.was});

  final String value;
  final String label;

  /// The value this row had when the editor opened. Sent back so a rename
  /// can move existing jobs.
  final String? was;

  Map<String, dynamic> toJson() => {
    'value': value,
    'label': label,
    if (was != null && was!.isNotEmpty) 'was': was,
  };
}

/// Settings and job payloads send either a string or `{value, label}`.
List<JobStatus> parseJobStatuses(dynamic raw) {
  if (raw is! List) return const [];

  final out = <JobStatus>[];

  for (final item in raw) {
    if (item is Map) {
      final value = '${item['value'] ?? item['key'] ?? ''}'.trim();
      final label = '${item['label'] ?? value}'.trim();
      if (value.isEmpty) continue;
      out.add(
        JobStatus(
          value: value,
          label: label.isEmpty ? _humanize(value) : label,
          was: '${item['was'] ?? value}',
        ),
      );
      continue;
    }

    final value = '$item'.trim();
    if (value.isEmpty) continue;
    out.add(JobStatus(value: value, label: _humanize(value), was: value));
  }

  return out;
}

String jobStatusLabel(dynamic raw, String value) {
  for (final status in parseJobStatuses(raw)) {
    if (status.value == value) return status.label;
  }
  return _humanize(value);
}

String _humanize(String value) {
  final words = value.replaceAll('_', ' ').trim();
  if (words.isEmpty) return value;
  return words
      .split(RegExp(r'\s+'))
      .map(
        (word) => word.isEmpty
            ? word
            : '${word[0].toUpperCase()}${word.substring(1)}',
      )
      .join(' ');
}

/// The colour a job status wears, read from its VALUE.
///
/// Statuses are the organization's own words (`jobs.statuses`), so there is no
/// enum to switch on: the defaults say `cancelled` and `completed`, and A-1's
/// imported ones say `Canceled` and `Done`. Keywords cover both, and anything
/// they do not recognise is [Tone.neutral] — a pill that guessed a colour for a
/// status it does not know would be telling somebody something untrue.
///
/// Order matters: "done pending approval" contains both "done" and "pending",
/// and is neither finished nor waiting on the customer.
Tone toneForJobStatus(String value) {
  final v = value.toLowerCase().replaceAll('_', ' ');

  if (v.contains('cancel')) return Tone.bad;
  if (v.contains('approval')) return Tone.review;
  if (v.startsWith('done') || v.contains('complete')) return Tone.ok;
  if (v.contains('progress')) return Tone.info;
  if (v.contains('pending') || v.contains('hold')) return Tone.caution;

  return Tone.neutral;
}
