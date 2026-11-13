import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import 'map_overlays.dart' show fieldLongDay;

/// Field › Route: a person's jobs for a day in the order Google finds
/// fastest, with drive times and a warning where they would be late.
///
/// It SUGGESTS; nothing here changes a job's time (Jose, 2026-09-25).
/// Navigate hands the stop to Google Maps, which on a phone opens the app.
class FieldRouteTab extends StatefulWidget {
  const FieldRouteTab({super.key, required this.api, required this.meId});

  final ApiClient api;
  final String? meId;

  @override
  State<FieldRouteTab> createState() => _FieldRouteTabState();
}

class _FieldRouteTabState extends State<FieldRouteTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _people = const [];
  String? _userId;
  DateTime _day = DateTime.now();
  Map<String, dynamic> _route = const {};

  @override
  void initState() {
    super.initState();
    _userId = widget.meId;
    _loadPeople();
    _load();
  }

  Future<void> _loadPeople() async {
    final response = await widget.api.get('/field/people');
    if (!mounted || !response.ok) return;
    setState(
      () => _people = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>(),
    );
  }

  String get _date => formatCalendarDay(_day);

  Future<void> _load() async {
    setState(() => _error = null);
    final response = await widget.api.get('/field/route', {
      'user_id': ?_userId,
      'date': _date,
    });
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (response.ok) {
        _route = response.body['data'] as Map<String, dynamic>;
      } else {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final stops = ((_route['stops'] as List?) ?? const [])
        .cast<Map<String, dynamic>>();
    final unroutable = ((_route['unroutable'] as List?) ?? const [])
        .cast<Map<String, dynamic>>();
    final choices = [
      if (widget.meId != null && !_people.any((p) => p['id'] == widget.meId))
        {'id': widget.meId, 'name': 'Me'},
      ..._people,
    ];

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        key: const ValueKey('tour-field-route'),
        padding: const EdgeInsets.all(16),
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              if (choices.length > 1)
                SizedBox(
                  key: const ValueKey('tour-field-route-person'),
                  width: 260,
                  child: SearchPickerField<String>(
                    label: 'Person',
                    isDense: true,
                    value: _userId,
                    options: [
                      for (final p in choices)
                        SearchOption(
                          value: '${p['id']}',
                          label: '${p['name']}',
                        ),
                    ],
                    onChanged: (id) {
                      setState(() => _userId = id);
                      _load();
                    },
                  ),
                ),
              ActionChip(
                key: const ValueKey('tour-field-route-day'),
                avatar: const Icon(Icons.event, size: 18),
                label: Text(fieldLongDay(_day)),
                onPressed: () async {
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: _day,
                    firstDate: DateTime.now().subtract(
                      const Duration(days: 30),
                    ),
                    lastDate: DateTime.now().add(const Duration(days: 60)),
                  );
                  if (picked == null) return;
                  setState(() => _day = picked);
                  _load();
                },
              ),
              ActionChip(
                key: const ValueKey('tour-field-route-refresh'),
                avatar: const Icon(Icons.refresh, size: 18),
                label: const Text('Refresh'),
                onPressed: _load,
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (_route['reason'] != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text('${_route['reason']}', style: muted),
            ),
          if (stops.isNotEmpty)
            Text(
              _route['starts_from'] == 'current_position'
                  ? 'Starting from where they are now.'
                  : 'Starting from the first job.',
              style: muted,
            ),
          if (stops.isEmpty && unroutable.isEmpty)
            Padding(
              key: const ValueKey('tour-field-route-empty'),
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                'No jobs on this day.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium,
              ),
            ),
          for (final (i, s) in stops.indexed)
            Card(
              child: ListTile(
                leading: CircleAvatar(child: Text('${i + 1}')),
                title: Text(
                  '${s['customer'] ?? s['title'] ?? 'Job'}'
                  '${s['number'] != null ? ' · ${s['number']}' : ''}',
                ),
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${s['address'] ?? ''}'),
                    Text(
                      [
                        'Booked ${_time(s['scheduled_for'] as String?)}',
                        if (s['drive_minutes'] != null)
                          '${s['drive_minutes']} min drive (${s['drive_km']} km)',
                        if (s['eta'] != null)
                          'arrives ${_time(s['eta'] as String?)}',
                      ].join(' · '),
                      style: muted,
                    ),
                    if (s['late'] == true)
                      Text(
                        'Late for the booked time',
                        style: TextStyle(color: theme.colorScheme.error),
                      ),
                  ],
                ),
                trailing: IconButton(
                  tooltip: 'Navigate',
                  icon: const Icon(Icons.navigation_outlined),
                  onPressed: () => launchUrl(
                    Uri.parse(s['navigate_url'] as String),
                    mode: LaunchMode.externalApplication,
                  ),
                ),
              ),
            ),
          if (unroutable.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text('Not on the route', style: theme.textTheme.titleSmall),
            for (final s in unroutable)
              ListTile(
                leading: const Icon(Icons.wrong_location_outlined),
                title: Text('${s['customer'] ?? s['title'] ?? 'Job'}'),
                subtitle: Text(
                  'Booked ${_time(s['scheduled_for'] as String?)} · ${s['why']}',
                ),
              ),
          ],
        ],
      ),
    );
  }
}

/// The booked wall time as written, read off the ISO string rather than
/// converted: 9:00 at the franchise is 9:00 wherever the reader sits.
String _time(String? iso) {
  if (iso == null || iso.length < 16) return '';
  final h = int.tryParse(iso.substring(11, 13)) ?? 0;
  final m = iso.substring(14, 16);

  return '${h % 12 == 0 ? 12 : h % 12}:$m ${h < 12 ? 'AM' : 'PM'}';
}
