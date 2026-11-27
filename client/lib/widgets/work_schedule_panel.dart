import 'package:flutter/material.dart';

import '../core/timestamps.dart';
import '../core/ui_strings.dart';
import 'setting_editors.dart' show TimeField;
import 'side_panel.dart';


String _title(String day) => day[0].toUpperCase() + day.substring(1, 3);

/// One row per weekday, a Day off switch, start and end (design 2026-09-08
/// §10.3). Shared by Human Resources › Records and Your account.
///
/// Not HoursButton: that editor's keys are open/close and its help text
/// blesses overnight ranges, which the server refuses here.
///
/// Returns the seven-day map (`{monday: {start, end} | null, …}`) on Save,
/// or null on Cancel.
Future<Map<String, dynamic>?> showWorkSchedulePanel(
  BuildContext context, {
  required String title,
  required Map<String, dynamic>? days,
  required DisplayFormats formats,
}) {
  final week = <String, dynamic>{
    for (final day in weekdayKeys)
      day: days?[day] is Map
          ? Map<String, dynamic>.from(days![day] as Map)
          : null,
  };

  return showSidePanel<Map<String, dynamic>>(
    context,
    builder: (context) => StatefulBuilder(
      builder: (context, repaint) => SidePanel(
        title: title,
        width: 480,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(week),
            child: const Text('Save'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final day in weekdayKeys)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  children: [
                    SizedBox(
                      width: 46,
                      child: Text(
                        _title(day),
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                    ),
                    Switch(
                      value: week[day] != null,
                      onChanged: (on) => repaint(() {
                        // A day switched on starts as the ordinary working
                        // day rather than at midnight.
                        week[day] = on
                            ? {'start': '08:00', 'end': '17:00'}
                            : null;
                      }),
                    ),
                    const SizedBox(width: 8),
                    if (week[day] == null)
                      Text(
                        UiStrings.t('time_clock.day_off'),
                        style: Theme.of(context).textTheme.bodyMedium,
                      )
                    else ...[
                      TimeField(
                        value: week[day]['start'] as String,
                        formats: formats,
                        onChanged: (v) =>
                            repaint(() => week[day]['start'] = v),
                      ),
                      const Text(' – '),
                      TimeField(
                        value: week[day]['end'] as String,
                        formats: formats,
                        onChanged: (v) => repaint(() => week[day]['end'] = v),
                      ),
                    ],
                  ],
                ),
              ),
            const SizedBox(height: 14),
            Text(
              UiStrings.t('time_clock.overnight'),
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    ),
  );
}
