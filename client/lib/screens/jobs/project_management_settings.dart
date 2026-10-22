import 'package:flutter/material.dart';

import '../../widgets/search_picker.dart';

/// The statuses Project Management watches; never saved empty.
const watchedKey = 'jobs.estimate_aging_statuses';

/// The statuses that mean cancelled; empty is a valid choice.
const cancelledKey = 'jobs.cancelled_statuses';

/// One organization setting Project Management reads: a list of job statuses.
class PmStatusSetting {
  const PmStatusSetting({
    required this.key,
    required this.value,
    required this.options,
    this.mayEdit = true,
    this.reason,
  });

  final String key;
  final List<String> value;

  /// The statuses it may hold, `(value, label)`, in the organization's order.
  final List<(String, String)> options;

  final bool mayEdit;

  /// Why not, in the server's words, when [mayEdit] is false.
  final String? reason;
}

/// Project Management › Settings (UI queue 127): which statuses it watches,
/// which mean cancelled, and the deadline as a plain sentence. Each list is a
/// row of removable chips and a picker to add one; every change is saved as
/// it is made, through the screen's [onSave].
class ProjectManagementSettings extends StatelessWidget {
  const ProjectManagementSettings({
    super.key,
    required this.watched,
    required this.cancelled,
    required this.onSave,
    this.deadlineDays = 30,
    this.saving = const {},
    this.errors = const {},
  });

  /// `jobs.estimate_aging_statuses`, or null before it has been read.
  final PmStatusSetting? watched;

  /// `jobs.cancelled_statuses`, or null before it has been read.
  final PmStatusSetting? cancelled;

  /// Saves a new list for a setting key.
  final void Function(String key, List<String> value) onSave;

  final int deadlineDays;

  /// Keys being saved now, and the last refusal per key.
  final Set<String> saving;
  final Map<String, String> errors;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final muted = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return ListView(
      padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24, vertical: 16),
      children: [
        _list(
          context,
          anchor: const ValueKey('tour-pm-settings-watched'),
          sentence: 'Watch jobs in these statuses:',
          setting: watched,
        ),
        const SizedBox(height: 24),
        _list(
          context,
          anchor: const ValueKey('tour-pm-settings-cancelled'),
          sentence: 'These statuses mean a job was cancelled:',
          setting: cancelled,
        ),
        const SizedBox(height: 24),
        Text(
          'A job cancels $deadlineDays days after its estimate.',
          key: const ValueKey('pm-settings-deadline'),
          style: muted,
        ),
      ],
    );
  }

  /// Saves [setting] without [v]. The watched list never goes empty; the
  /// cancelled list may, and says so with Undo rather than a question.
  void _remove(BuildContext context, PmStatusSetting setting, String v) {
    final previous = setting.value;
    final next = [
      for (final x in previous)
        if (x != v) x,
    ];
    if (next.isEmpty && setting.key == watchedKey) return;
    onSave(setting.key, next);
    if (next.isEmpty && setting.key == cancelledKey) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        SnackBar(
          key: const ValueKey('pm-settings-cancelled-undo'),
          content: const Text('Nothing counts as cancelled now'),
          action: SnackBarAction(
            label: 'Undo',
            onPressed: () => onSave(setting.key, previous),
          ),
        ),
      );
    }
  }

  Widget _list(
    BuildContext context, {
    required Key anchor,
    required String sentence,
    required PmStatusSetting? setting,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    if (setting == null) {
      return Column(
        key: anchor,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(sentence, style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          const LinearProgressIndicator(),
        ],
      );
    }

    String label(String value) => setting.options
        .firstWhere((o) => o.$1 == value, orElse: () => (value, value))
        .$2;

    final busy = saving.contains(setting.key);
    final editable = setting.mayEdit && !busy;
    final left = [
      for (final o in setting.options)
        if (!setting.value.contains(o.$1)) o,
    ];
    final error = errors[setting.key];
    // An empty watched list empties every Project Management list.
    final keepsLast = setting.key == watchedKey && setting.value.length == 1;

    return Column(
      key: anchor,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          sentence,
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (setting.value.isEmpty)
              Text(
                'None yet.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
            for (final v in setting.value)
              InputChip(
                key: ValueKey('pm-settings-${setting.key}-$v'),
                label: Text(label(v)),
                onDeleted: editable && !keepsLast
                    ? () => _remove(context, setting, v)
                    : null,
                deleteButtonTooltipMessage: 'Remove',
              ),
            if (setting.mayEdit && left.isNotEmpty)
              SizedBox(
                width: 220,
                child: SearchPickerField<String>(
                  key: ValueKey('pm-settings-${setting.key}-add'),
                  value: null,
                  hint: '+ Add a status',
                  isDense: true,
                  options: [
                    for (final (value, text) in left)
                      SearchOption(value: value, label: text),
                  ],
                  onChanged: editable
                      ? (v) {
                          if (v != null) {
                            onSave(setting.key, [...setting.value, v]);
                          }
                        }
                      : null,
                ),
              ),
          ],
        ),
        if (keepsLast && setting.mayEdit) ...[
          const SizedBox(height: 6),
          Text(
            'Keep at least one status; add another before removing this one.',
            key: ValueKey('pm-settings-${setting.key}-keep-one'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        ],
        if (!setting.mayEdit && setting.reason != null) ...[
          const SizedBox(height: 6),
          Text(
            setting.reason!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        ],
        if (error != null) ...[
          const SizedBox(height: 6),
          Text(
            error,
            key: ValueKey('pm-settings-${setting.key}-error'),
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
          ),
        ],
      ],
    );
  }
}
