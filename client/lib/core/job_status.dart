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
