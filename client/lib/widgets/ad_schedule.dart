import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import 'open_destination.dart';
import 'search_picker.dart';
import 'side_panel.dart';

/// A campaign's on/off schedule: the clock chip beside its switch, and the
/// panel behind it (Jose, 2026-11-05: "can we build a switch so it can turn it
/// on/off at designated times?"; design 185, option 1 + phone 4). Shared by the
/// Meta and the Google campaign tables. The server words every time in the
/// schedule's own timezone (`summary`, `next_change`, `last_change`).
class AdScheduleChip extends StatelessWidget {
  const AdScheduleChip({
    super.key,
    required this.schedule,
    required this.canManage,
    required this.onTap,
  });

  /// The `ad-schedules` row, or null when the campaign has none.
  final Map<String, dynamic>? schedule;
  final bool canManage;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final s = schedule;
    if (s == null && !canManage) return const SizedBox.shrink();

    final failed = s?['last_error'] != null;
    final colour = failed
        ? StateColour.of(context).caution
        : s == null
        ? theme.colorScheme.onSurfaceVariant
        : theme.colorScheme.primary;
    final text = s == null
        ? '＋ Schedule'
        : failed
        ? "Couldn't turn ${s['wants']}"
        : [s['summary'], s['next_change']].whereType<String>().join(' · ');

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          border: Border.all(
            color: colour.withValues(alpha: s == null ? 0.4 : 0.6),
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              failed ? Icons.warning_amber_outlined : Icons.schedule,
              size: 14,
              color: colour,
            ),
            const SizedBox(width: 4),
            Flexible(
              child: Text(
                text,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(color: colour),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Every half hour, as the panel offers it.
final _times = [
  for (var m = 0; m < 24 * 60; m += 30)
    '${(m ~/ 60).toString().padLeft(2, '0')}:${(m % 60).toString().padLeft(2, '0')}',
];

String _clock(String hm) {
  final h = int.parse(hm.substring(0, 2));
  final m = hm.substring(3);
  return '${h % 12 == 0 ? 12 : h % 12}:$m ${h < 12 ? 'AM' : 'PM'}';
}

const _zones = {
  'America/New_York': 'Eastern',
  'America/Chicago': 'Central',
  'America/Denver': 'Mountain',
  'America/Phoenix': 'Arizona',
  'America/Los_Angeles': 'Pacific',
  'America/Anchorage': 'Alaska',
  'Pacific/Honolulu': 'Hawaii',
};

const _dayLetters = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

/// Opens the panel; true when the schedule was saved or removed.
Future<bool> editAdSchedule(
  BuildContext context, {
  required ApiClient api,
  required String connectionId,
  required String objectId,
  required String objectName,
  required Map<String, dynamic>? schedule,
  required bool canManage,
}) async =>
    await showSidePanel<bool>(
      context,
      builder: (_) => _AdSchedulePanel(
        api: api,
        connectionId: connectionId,
        objectId: objectId,
        objectName: objectName,
        schedule: schedule,
        canManage: canManage,
      ),
    ) ==
    true;

class _AdSchedulePanel extends StatefulWidget {
  const _AdSchedulePanel({
    required this.api,
    required this.connectionId,
    required this.objectId,
    required this.objectName,
    required this.schedule,
    required this.canManage,
  });

  final ApiClient api;
  final String connectionId;
  final String objectId;
  final String objectName;
  final Map<String, dynamic>? schedule;
  final bool canManage;

  @override
  State<_AdSchedulePanel> createState() => _AdSchedulePanelState();
}

class _AdSchedulePanelState extends State<_AdSchedulePanel> {
  late final Set<int> _days = {
    ...((widget.schedule?['days'] as List?) ?? const [1, 2, 3, 4, 5]).map(
      (d) => (d as num).toInt(),
    ),
  };
  late String _on = (widget.schedule?['on_at'] as String?) ?? '08:00';
  late String _off = (widget.schedule?['off_at'] as String?) ?? '18:00';

  /// Null: the organization's own, which the server fills in.
  late String? _zone = widget.schedule?['timezone'] as String?;

  bool _busy = false;
  bool _confirmRemove = false;
  Timer? _lapse;
  String? _error;

  @override
  void dispose() {
    _lapse?.cancel();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.put('/ad-schedules', {
      'connection_id': widget.connectionId,
      'object_id': widget.objectId,
      'object_name': widget.objectName,
      'days': (_days.toList()..sort()),
      'on_at': _on,
      'off_at': _off,
      if (_zone != null) 'timezone': _zone,
    });
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _busy = false;
      _error = response.message;
    });
  }

  Future<void> _remove() async {
    setState(() => _busy = true);
    final response = await widget.api.delete(
      '/ad-schedules/${widget.schedule!['id']}',
    );
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _busy = false;
      _confirmRemove = false;
      _error = response.message;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final s = widget.schedule;
    final edit = widget.canManage && !_busy;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final open = OpenDestination.of(context);
    final zone = _zone;

    return SidePanel(
      title: 'Schedule · ${widget.objectName}',
      actions: [
        if (widget.canManage)
          FilledButton(
            key: const ValueKey('ad-schedule-save'),
            onPressed: edit && _days.isNotEmpty && _on != _off ? _save : null,
            child: Text(s == null ? 'Set schedule' : 'Save'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (s?['last_error'] != null) ...[
            Text(
              '${s!['last_error']}',
              key: const ValueKey('ad-schedule-refusal'),
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
            if (open != null)
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton(
                  onPressed: () => open('settings'),
                  child: const Text('Reconnect in Settings › Integrations'),
                ),
              ),
            const SizedBox(height: 12),
          ],
          Text('Days', style: theme.textTheme.titleSmall),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            children: [
              for (var d = 1; d <= 7; d++)
                FilterChip(
                  key: ValueKey('ad-schedule-day-$d'),
                  label: Text(_dayLetters[d - 1]),
                  selected: _days.contains(d),
                  onSelected: edit
                      ? (on) =>
                            setState(() => on ? _days.add(d) : _days.remove(d))
                      : null,
                ),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: SearchPickerField<String>(
                  key: const ValueKey('ad-schedule-on'),
                  label: 'Turn on',
                  value: _on,
                  options: [
                    for (final t in _times)
                      SearchOption(value: t, label: _clock(t)),
                  ],
                  onChanged: edit
                      ? (v) => setState(() => _on = v ?? _on)
                      : null,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: SearchPickerField<String>(
                  key: const ValueKey('ad-schedule-off'),
                  label: 'Turn off',
                  value: _off,
                  options: [
                    for (final t in _times)
                      SearchOption(value: t, label: _clock(t)),
                  ],
                  onChanged: edit
                      ? (v) => setState(() => _off = v ?? _off)
                      : null,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          SearchPickerField<String?>(
            key: const ValueKey('ad-schedule-zone'),
            label: 'Timezone',
            value: zone,
            options: [
              const SearchOption(value: null, label: "The organization's"),
              for (final e in {
                ..._zones,
                if (zone != null && !_zones.containsKey(zone)) zone: zone,
              }.entries)
                SearchOption(value: e.key, label: e.value, detail: e.key),
            ],
            onChanged: edit ? (v) => setState(() => _zone = v) : null,
          ),
          const SizedBox(height: 12),
          Text(
            'An off time earlier than the on time runs overnight. Switching a '
            'campaign by hand holds until the next scheduled change.',
            style: muted,
          ),
          if (s != null) ...[
            const SizedBox(height: 16),
            if (s['next_change'] != null)
              Text('Next: turns ${s['next_change']}', style: muted),
            if (s['last_change'] != null)
              Text('Last: ${s['last_change']}', style: muted),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              _error!,
              key: const ValueKey('ad-schedule-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
          if (s != null && widget.canManage) ...[
            const SizedBox(height: 24),
            if (!_confirmRemove)
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton(
                  key: const ValueKey('ad-schedule-remove'),
                  style: TextButton.styleFrom(
                    foregroundColor: scheme(context).error,
                  ),
                  onPressed: _busy
                      ? null
                      : () {
                          setState(() => _confirmRemove = true);
                          _lapse?.cancel();
                          _lapse = Timer(const Duration(seconds: 5), () {
                            if (mounted) setState(() => _confirmRemove = false);
                          });
                        },
                  child: const Text('Remove schedule'),
                ),
              )
            else
              Container(
                key: const ValueKey('ad-schedule-remove-confirm'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: scheme(context).errorContainer,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(
                      'The campaign stays as it is now.',
                      style: TextStyle(color: scheme(context).onErrorContainer),
                    ),
                    FilledButton(
                      key: const ValueKey('ad-schedule-remove-go'),
                      style: FilledButton.styleFrom(
                        backgroundColor: scheme(context).error,
                        foregroundColor: scheme(context).onError,
                      ),
                      onPressed: _busy ? null : _remove,
                      child: const Text('Remove schedule'),
                    ),
                    TextButton(
                      onPressed: () => setState(() => _confirmRemove = false),
                      child: const Text('Cancel'),
                    ),
                  ],
                ),
              ),
          ],
        ],
      ),
    );
  }

  static ColorScheme scheme(BuildContext context) =>
      Theme.of(context).colorScheme;
}
