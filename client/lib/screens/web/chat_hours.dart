import 'package:flutter/material.dart';

import '../../core/timestamps.dart';
import '../../widgets/search_picker.dart';
import 'hours.dart';

/// The hours the live chat is on the customer's website at all.
///
/// Jose, 2026-09-17: *"it needs to be able to be set so it works only on
/// certain work hours, cause if no one is there to answer it's bad ... on work
/// hours livechat should appear on the websites and outside, it should
/// disappear."*
///
/// ── Why this is not the `hours` SEO field ─────────────────────────
///
/// The obvious reuse, and it is wrong. That field is what the business
/// PUBLISHES — a string, rendered by `[ezbizi_hours]`, which `parseWeek` can
/// read back only when the grid wrote it. A customer who types "By appointment
/// only" into their published hours would silently lose their chat. A gate that
/// fails on free text fails hardest on the businesses least like a shop.
///
/// So this is its own schedule, stored inside the chat's config, and it borrows
/// only the model and the formatting from `hours.dart` — [DayHours],
/// [shortWeekdays], [formatTime], [defaultWeek].
///
/// ── Empty means always on ─────────────────────────────────────────
///
/// There is no switch, and not only because Jose removed the widget switches on
/// 2026-09-15. A schedule that exists is the decision to limit; no schedule is
/// the decision not to. Every site on the estate has a chat today, so anything
/// that defaulted to office hours would take the bubble off a hundred websites
/// at the next update, at whatever hour it landed, with nothing logged.
class ChatHoursField extends StatelessWidget {
  const ChatHoursField({
    super.key,
    required this.hours,
    required this.timezone,
    required this.enabled,
    required this.onChanged,
  });

  /// Seven entries Monday first, or empty for "always on".
  final List<Map<String, dynamic>> hours;
  final String timezone;
  final bool enabled;

  /// Both at once, because half a gate is no gate: the server drops a schedule
  /// with no zone and a zone with no schedule, so sending them separately would
  /// mean a save that silently discards what was just typed.
  final void Function(List<Map<String, dynamic>> hours, String timezone)
  onChanged;

  /// The zones EzBizi's customers are actually in, plus the ones a franchise
  /// might reach into.
  ///
  /// Not the full IANA list — that is about 600 names, and a searchable list of
  /// 600 is a worse way to find "Florida" than a list of twelve. The server
  /// checks whatever arrives against PHP's own list, so a name added here later
  /// cannot be one the browser will choke on. If somebody needs a zone that is
  /// missing, add it here rather than loosening the check.
  static const List<(String, String)> zones = [
    ('America/New_York', 'Eastern — New York, Florida, Georgia'),
    ('America/Chicago', 'Central — Chicago, Texas'),
    ('America/Denver', 'Mountain — Denver, Utah'),
    ('America/Phoenix', 'Arizona — no daylight saving'),
    ('America/Los_Angeles', 'Pacific — California, Washington'),
    ('America/Anchorage', 'Alaska'),
    ('Pacific/Honolulu', 'Hawaii'),
    ('America/Toronto', 'Eastern — Ontario, Quebec'),
    ('America/Vancouver', 'Pacific — British Columbia'),
    ('America/Puerto_Rico', 'Puerto Rico'),
    ('Europe/London', 'United Kingdom'),
    ('Europe/Madrid', 'Spain'),
  ];

  List<DayHours> get _week {
    if (hours.length != 7) return defaultWeek();

    return [
      for (final day in hours)
        day['closed'] == true
            ? const DayHours.shut()
            : DayHours(
                closed: false,
                open: _time(day['open']) ?? const TimeOfDay(hour: 8, minute: 0),
                close:
                    _time(day['close']) ?? const TimeOfDay(hour: 17, minute: 0),
              ),
    ];
  }

  static TimeOfDay? _time(Object? raw) {
    final match = RegExp(r'^(\d{2}):(\d{2})$').firstMatch('${raw ?? ''}');
    if (match == null) return null;

    return TimeOfDay(
      hour: int.parse(match.group(1)!),
      minute: int.parse(match.group(2)!),
    );
  }

  /// `08:00`, padded, because the browser's own check wants two digits and
  /// reads `8:00` as unparseable — which fails open and shows the chat all
  /// night.
  static String _hhmm(TimeOfDay t) =>
      '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

  static List<Map<String, dynamic>> _wire(List<DayHours> week) => [
    for (final day in week)
      {
        'closed': day.closed,
        'open': _hhmm(day.open),
        'close': _hhmm(day.close),
      },
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (hours.isEmpty) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Chat hours', style: theme.textTheme.bodyMedium),
          const SizedBox(height: 4),
          Text(
            'The chat is on every page, at every hour. Set hours and the bubble '
            'disappears outside them — so nobody starts a conversation when '
            'there is no one to answer it.',
            style: theme.textTheme.bodySmall,
          ),
          if (enabled)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: () => onChanged(
                  _wire(defaultWeek()),
                  timezone.isEmpty ? zones.first.$1 : timezone,
                ),
                icon: const Icon(Icons.schedule_outlined, size: 18),
                label: const Text('Set chat hours'),
              ),
            ),
        ],
      );
    }

    final week = _week;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Chat hours', style: theme.textTheme.bodyMedium),
            ),
            if (enabled)
              TextButton(
                // Clearing IS the "always on" setting, so it says what it does
                // rather than "Remove".
                onPressed: () => onChanged(const [], ''),
                child: const Text('Show at all hours'),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'Read on the business’s clock, not the visitor’s — somebody browsing '
          'from another state still sees the bubble only while you are open.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        SearchPickerField<String>(
          value: zones.any((z) => z.$1 == timezone) ? timezone : null,
          label: 'Time zone',
          isDense: true,
          hint: 'Which clock these hours are on',
          options: [
            for (final zone in zones)
              SearchOption(value: zone.$1, label: zone.$2, detail: zone.$1),
          ],
          onChanged: enabled
              ? (value) => onChanged(_wire(week), value ?? '')
              : null,
        ),
        const SizedBox(height: 8),
        for (var day = 0; day < 7; day++)
          _DayRow(
            name: shortWeekdays[day],
            day: week[day],
            enabled: enabled,
            onChanged: (changed) {
              final next = [...week]..[day] = changed;
              onChanged(_wire(next), timezone);
            },
          ),
      ],
    );
  }
}

class _DayRow extends StatelessWidget {
  const _DayRow({
    required this.name,
    required this.day,
    required this.enabled,
    required this.onChanged,
  });

  final String name;
  final DayHours day;
  final bool enabled;
  final ValueChanged<DayHours> onChanged;

  Future<void> _pick(BuildContext context, {required bool opening}) async {
    final picked = await showTimePicker(
      context: context,
      initialTime: opening ? day.open : day.close,
      helpText: '$name — chat ${opening ? 'starts' : 'stops'}',
    );

    if (picked == null) return;

    onChanged(
      DayHours(
        closed: false,
        open: opening ? picked : day.open,
        close: opening ? day.close : picked,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          SizedBox(width: 44, child: Text(name, style: theme.textTheme.bodyMedium)),
          SizedBox(
            width: 44,
            child: Checkbox(
              value: !day.closed,
              onChanged: enabled
                  ? (on) => onChanged(
                      on == true
                          ? const DayHours(
                              closed: false,
                              open: TimeOfDay(hour: 8, minute: 0),
                              close: TimeOfDay(hour: 17, minute: 0),
                            )
                          : const DayHours.shut(),
                    )
                  : null,
            ),
          ),
          if (day.closed)
            Expanded(
              child: Text(
                'No chat this day',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            )
          else ...[
            Expanded(
              child: TextButton(
                onPressed: enabled ? () => _pick(context, opening: true) : null,
                child: Text(formatTime(day.open)),
              ),
            ),
            const Text('–'),
            Expanded(
              child: TextButton(
                onPressed: enabled ? () => _pick(context, opening: false) : null,
                child: Text(formatTime(day.close)),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
