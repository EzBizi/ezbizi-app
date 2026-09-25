import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/field_tracker.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';

/// Field › Dashboard: who is out, what needs a manager, and, for somebody
/// who is tracked, the SOS button.
class FieldDashboardTab extends StatefulWidget {
  const FieldDashboardTab({
    super.key,
    required this.api,
    required this.canAcknowledge,
  });

  final ApiClient api;
  final bool canAcknowledge;

  @override
  State<FieldDashboardTab> createState() => _FieldDashboardTabState();
}

const fieldAlertLabels = {
  'sos': 'SOS',
  'stopped': 'Stopped too long',
  'signal_lost': 'Lost signal',
};

class _FieldDashboardTabState extends State<FieldDashboardTab> {
  bool _loading = true;
  String? _error;
  String? _actionError;
  Map<String, dynamic> _data = const {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    final response = await widget.api.get('/field/dashboard');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (response.ok) {
        _data = response.body['data'] as Map<String, dynamic>;
      } else {
        _error = response.message;
      }
    });
  }

  Future<void> _acknowledge(String id) async {
    final response = await widget.api.post('/field/alerts/$id/acknowledge', {});
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _actionError = response.message);
      return;
    }
    _load();
  }

  Future<void> _sos() async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Send SOS?'),
        content: const Text(
          'Your managers are told straight away, with where you are now.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Send SOS'),
          ),
        ],
      ),
    );
    if (sure != true || !mounted) return;

    final here = await FieldTracker.currentPosition();
    if (!mounted) return;
    if (here == null) {
      setState(
        () => _actionError =
            'Could not read your location. Allow location for EzBizi and try '
            'again, or phone your manager.',
      );
      return;
    }

    final response = await widget.api.post('/field/sos', {
      'lat': here.latitude,
      'lng': here.longitude,
    });
    if (!mounted) return;
    setState(
      () => _actionError = response.ok
          ? null
          : 'SOS not sent: ${response.message}',
    );
    if (response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('SOS sent.')));
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final counts = (_data['counts'] as Map?) ?? const {};
    final me = (_data['me'] as Map?) ?? const {};
    final alerts = ((_data['alerts'] as List?) ?? const [])
        .cast<Map<String, dynamic>>();

    Widget tile(String label, Object? value, IconData icon) => SizedBox(
      width: 160,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 20, color: theme.colorScheme.primary),
              const SizedBox(height: 8),
              Text('${value ?? 0}', style: theme.textTheme.headlineSmall),
              Text(label, style: theme.textTheme.bodySmall),
            ],
          ),
        ),
      ),
    );

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (me['tracked'] == true) ...[
              Card(
                child: ListTile(
                  leading: Icon(
                    me['should_track'] == true
                        ? Icons.location_on
                        : Icons.location_off_outlined,
                  ),
                  title: Text('${me['reason'] ?? ''}'),
                  trailing: FilledButton.icon(
                    style: FilledButton.styleFrom(
                      backgroundColor: theme.colorScheme.error,
                      foregroundColor: theme.colorScheme.onError,
                    ),
                    onPressed: _sos,
                    icon: const Icon(Icons.sos),
                    label: const Text('SOS'),
                  ),
                ),
              ),
              const SizedBox(height: 12),
            ],
            if (_actionError != null) ...[
              ErrorNote(_actionError!),
              const SizedBox(height: 12),
            ],
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                tile('Tracked', counts['tracked'], Icons.people_outline),
                tile('Reporting now', counts['reporting'], Icons.gps_fixed),
                tile(
                  'At a job',
                  counts['at_job'],
                  Icons.home_repair_service_outlined,
                ),
                tile(
                  'Stopped too long',
                  counts['stopped'],
                  Icons.pause_circle_outline,
                ),
                tile(
                  'Lost signal',
                  counts['silent'],
                  Icons.signal_cellular_off,
                ),
                tile('Open alerts', counts['open_alerts'], Icons.warning_amber),
              ],
            ),
            const SizedBox(height: 16),
            Text('Alerts', style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            if (alerts.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 16),
                child: Text(
                  'No alerts in the last day.',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            for (final a in alerts)
              Card(
                child: ListTile(
                  leading: Icon(
                    a['kind'] == 'sos' ? Icons.sos : Icons.warning_amber,
                    color: a['acknowledged_at'] == null
                        ? theme.colorScheme.error
                        : theme.colorScheme.onSurfaceVariant,
                  ),
                  title: Text(
                    '${fieldAlertLabels[a['kind']] ?? a['kind']} · '
                    '${(a['user'] as Map?)?['name'] ?? ''}',
                  ),
                  subtitle: Text(
                    '${_when(a['created_at'] as String?)}'
                    '${a['acknowledged_at'] != null ? ' · acknowledged' : ''}',
                  ),
                  trailing:
                      widget.canAcknowledge && a['acknowledged_at'] == null
                      ? TextButton(
                          onPressed: () => _acknowledge(a['id'] as String),
                          child: const Text('Acknowledge'),
                        )
                      : null,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

String _when(String? iso) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return '';
  final m = at.minute.toString().padLeft(2, '0');
  final h = at.hour % 12 == 0 ? 12 : at.hour % 12;

  return '${at.month}/${at.day} $h:$m ${at.hour < 12 ? 'AM' : 'PM'}';
}
