import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';

/// Dash cam safety events (Jose, 2026-11-16), under the camera grid. Hidden
/// until a vendor sends one. Play asks the server for a fresh vendor link and
/// opens it: the video is never downloaded into EzBizi (Jose 10-27).
class DashcamEventsPanel extends StatefulWidget {
  const DashcamEventsPanel({super.key, required this.api});

  final ApiClient api;

  static const types = {
    'harsh_brake': 'Harsh braking',
    'harsh_accel': 'Harsh acceleration',
    'harsh_turn': 'Harsh turn',
    'collision': 'Collision',
    'distracted': 'Distracted driving',
    'speeding': 'Speeding',
    'other': 'Other',
  };

  @override
  State<DashcamEventsPanel> createState() => _DashcamEventsPanelState();
}

class _DashcamEventsPanelState extends State<DashcamEventsPanel> {
  List<Map<String, dynamic>> _events = const [];
  String? _type;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/monitoring/dashcam-events', {
      'type': ?_type,
    });
    if (!mounted || !r.ok) return;
    setState(() => _events = (r.data as List).cast<Map<String, dynamic>>());
  }

  Future<void> _play(Map<String, dynamic> e) async {
    setState(() => _error = null);
    final r = await widget.api.post(
      '/monitoring/dashcam-events/${e['id']}/clip-url',
      {},
    );
    final url = r.ok && r.data is Map
        ? (r.data as Map)['url'] as String?
        : null;
    if (url == null) {
      if (mounted) setState(() => _error = 'This video is not available.');
      return;
    }
    await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  }

  @override
  Widget build(BuildContext context) {
    if (_events.isEmpty && _type == null) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Column(
      key: const Key('dashcam-events'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 24),
        Text('Dash cam events', style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final (key, label) in [
              (null, 'All'),
              for (final t in DashcamEventsPanel.types.entries)
                (t.key, t.value),
            ])
              ChoiceChip(
                key: Key('dashcam-type-${key ?? 'all'}'),
                label: Text(label),
                selected: _type == key,
                onSelected: (_) {
                  setState(() => _type = key);
                  _load();
                },
              ),
          ],
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        const SizedBox(height: 8),
        if (_events.isEmpty)
          Text('No events of this kind.', style: theme.textTheme.bodyMedium)
        else
          for (final e in _events)
            ListTile(
              key: Key('dashcam-event-${e['id']}'),
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.warning_amber_outlined),
              title: Text(
                DashcamEventsPanel.types[e['type']] ?? '${e['type']}',
              ),
              subtitle: Text(
                [
                  (e['vehicle'] as Map?)?['name'],
                  (e['driver'] as Map?)?['name'],
                  _when(e['occurred_at'] as String?),
                ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
              ),
              trailing: e['has_clip'] == true
                  ? TextButton.icon(
                      key: Key('dashcam-play-${e['id']}'),
                      onPressed: () => _play(e),
                      icon: const Icon(Icons.play_circle_outline),
                      label: const Text('Play'),
                    )
                  : null,
            ),
      ],
    );
  }

  String? _when(String? iso) {
    final t = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
    if (t == null) return null;
    final ml = MaterialLocalizations.of(context);
    return '${ml.formatShortDate(t)} ${ml.formatTimeOfDay(TimeOfDay.fromDateTime(t))}';
  }
}
