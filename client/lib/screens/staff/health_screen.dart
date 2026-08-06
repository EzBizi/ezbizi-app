import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../widgets/async_body.dart';

/// The subsystems that fail silently (plan §4.2, §18 Phase 0).
///
/// Backups, mail and the outbox all fail the same way: they stop, and nothing
/// about the app looks different. The scheduler was not running for the first
/// week — no error, no alert, the outbox just filled up. Mail is a transport
/// that accepts every message and returns success. The backup wrote files to the
/// disk it was protecting and exited zero.
///
/// So this screen answers the only question that catches those: when did it last
/// actually work, and is that recent enough.
class HealthScreen extends StatefulWidget {
  const HealthScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<HealthScreen> createState() => _HealthScreenState();
}

class _HealthScreenState extends State<HealthScreen> {
  final _address = TextEditingController();

  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _health;
  String? _testResult;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _address.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/health');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _health = response.ok ? response.body : null;
    });
  }

  Future<void> _sendTest() async {
    setState(() {
      _sending = true;
      _testResult = null;
    });

    final response = await widget.staff.api
        .post('/staff/health/test-email', {'address': _address.text.trim()});

    if (!mounted) return;

    setState(() {
      _sending = false;
      _testResult = response.ok
          ? response.body['message'] as String?
          : response.message;
    });
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Row(
            children: [
              Expanded(
                child: Text('Health',
                    style: Theme.of(context).textTheme.headlineSmall),
              ),
              IconButton(
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                tooltip: 'Check again',
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'Three subsystems that stop without complaining. Everything here is '
            'a read of when it last actually worked, not whether it answers.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 20),
          if (_health != null) ...[
            _backups(_health!['backups'] as Map<String, dynamic>),
            _mail(_health!['mail'] as Map<String, dynamic>),
            _outbox(_health!['outbox'] as Map<String, dynamic>),
          ],
        ],
      ),
    );
  }

  Widget _card({
    required bool ok,
    required String title,
    required String headline,
    List<Widget> children = const [],
  }) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              ok ? Icons.check_circle_outline : Icons.error_outline,
              color: ok ? scheme.primary : scheme.error,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 4),
                  Text(headline, style: Theme.of(context).textTheme.bodyMedium),
                  ...children,
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _backups(Map<String, dynamic> backups) {
    final ok = backups['ok'] == true;
    final age = backups['age_hours'];
    final verified = backups['verified'] == true;

    return _card(
      ok: ok,
      title: 'Backups',
      headline: ok
          // Verified, not merely written — the distinction is the whole point.
          // An unverified backup is indistinguishable from a working one until
          // the day it matters.
          ? 'Last verified restore ${age == null ? "recently" : "$age hours ago"}, '
              'from ${backups['restored_from'] ?? 'the latest dump'}.'
          : (backups['problem'] as String? ??
              (backups['errors'] as List<dynamic>? ?? []).join(', ')),
      children: [
        if (ok && !verified)
          const Padding(
            padding: EdgeInsets.only(top: 6),
            child: Text('Written but never restored — the check did not finish.'),
          ),
      ],
    );
  }

  Widget _mail(Map<String, dynamic> mail) {
    final delivers = mail['delivers'] == true;
    final missing = (mail['missing'] as List<dynamic>).cast<String>();

    return _card(
      ok: delivers,
      title: 'Mail',
      headline: delivers
          ? 'Sending through ${mail['transport']} as ${mail['from']}.'
          : missing.isEmpty
              ? 'The "${mail['transport']}" transport does not deliver anywhere. '
                  'Password resets are written to the log and reach nobody.'
              : 'Not configured — missing ${missing.join(', ')}.',
      children: [
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _address,
                decoration: const InputDecoration(
                  labelText: 'Send a test to',
                  isDense: true,
                  border: OutlineInputBorder(),
                ),
              ),
            ),
            const SizedBox(width: 8),
            FilledButton.tonal(
              onPressed: _sending || _address.text.trim().isEmpty ? null : _sendTest,
              child: Text(_sending ? 'Sending…' : 'Send'),
            ),
          ],
        ),
        if (_testResult != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_testResult!,
                style: Theme.of(context).textTheme.bodySmall),
          ),
      ],
    );
  }

  Widget _outbox(Map<String, dynamic> outbox) {
    final ok = outbox['ok'] == true;
    final oldest = outbox['oldest_minutes'];
    final failed = outbox['failed_jobs'] as int;

    return _card(
      ok: ok && failed == 0,
      title: 'Workflow outbox',
      headline: ok
          ? 'Nothing waiting. The scheduler is sweeping.'
          // A backlog here means schedule:run has stopped — the outbox is the
          // only symptom that subsystem produces.
          : 'The oldest undelivered event is $oldest minutes old. '
              'The scheduler has stopped running.',
      children: [
        if (failed > 0)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text('$failed failed queue job${failed == 1 ? '' : 's'}.'),
          ),
      ],
    );
  }
}
