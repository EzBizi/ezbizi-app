import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../widgets/async_body.dart';

/// The subsystems that fail silently (plan §4.2, §18 Phase 0).
///
/// Backups, the certificate, mail and the outbox all fail the same way: they
/// stop, and nothing about the app looks different. The scheduler was not
/// running for the first week — no error, no alert, the outbox just filled up.
/// Mail is a transport that accepts every message and returns success. The
/// backup wrote files to the disk it was protecting and exited zero. And certbot
/// renews on time while nginx serves the copy it loaded at boot, so the
/// certificate is the one whose failure already has a date on it.
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

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it: the kill switch could sit
  /// reading "Off" for a module that was on, until somebody navigated away.
  int _request = 0;
  String? _error;
  Map<String, dynamic>? _health;

  /// What the apps reported going wrong (2026-10-15). Loaded beside the five
  /// subsystems; its own failure leaves the rest of the screen standing.
  Map<String, dynamic>? _issues;
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
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. The four
      // cards are the screen, so their absence is the test. "Check again" used
      // to replace them — and the button that had just been pressed — with a
      // spinner, which on a screen read during an incident is the worst moment
      // to lose the numbers you were comparing against.
      _loading = _health == null;
      _error = null;
    });

    final results = await Future.wait([
      widget.staff.api.get('/staff/health'),
      widget.staff.api.get('/staff/health/client-issues'),
    ]);
    final response = results[0];

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _health = response.ok ? response.body : null;
      _issues = results[1].ok ? results[1].body : null;
    });
  }

  Future<void> _sendTest() async {
    setState(() {
      _sending = true;
      _testResult = null;
    });

    final response = await widget.staff.api.post('/staff/health/test-email', {
      'address': _address.text.trim(),
    });

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
                child: Text(
                  'Health',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
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
            'Five subsystems that stop without complaining. Everything here is '
            'a read of when it last actually worked, not whether it answers.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 20),
          if (_health != null) ...[
            _backups(_health!['backups'] as Map<String, dynamic>),
            _peer(_health!['peer'] as Map<String, dynamic>?),
            _certificate(_health!['certificate'] as Map<String, dynamic>?),
            _mail(_health!['mail'] as Map<String, dynamic>),
            _outbox(_health!['outbox'] as Map<String, dynamic>),
          ],
          if (_issues != null) _appIssues(_issues!),
        ],
      ),
    );
  }

  Widget _card({
    Key? key,
    required bool ok,
    required String title,
    required String headline,
    List<Widget> children = const [],
  }) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      key: key,
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              ok ? Icons.check_circle_outline : Icons.error_outline,
              color: ok ? scheme.primary : scheme.error,
              semanticLabel: ok ? 'Healthy' : 'Needs attention',
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

  Widget _peer(Map<String, dynamic>? peer) {
    if (peer == null) return const SizedBox.shrink();

    final ok = peer['ok'] == true;

    return _card(
      ok: ok,
      title: 'The other box',
      headline: ok
          ? '${peer['host'] ?? 'The websites box'} answered recently.'
          : (peer['problem'] as String? ??
                'The other box has not been heard from.'),
    );
  }

  /// TLS expiry.
  ///
  /// Here because the failure it reports is dated and silent: certbot renews on
  /// schedule, and nginx keeps serving the certificate it loaded at boot until
  /// something reloads it. Nothing on the box says so, and the first symptom is
  /// both hostnames refusing connections on the same morning — including this
  /// screen, which is why the number has to be visible long before then.
  ///
  /// Null-tolerant: an older server that does not send the key yet leaves this
  /// card out rather than crashing the page it is a diagnostic for.
  Widget _certificate(Map<String, dynamic>? certificate) {
    if (certificate == null) return const SizedBox.shrink();

    final ok = certificate['ok'] == true;
    final days = certificate['days_remaining'];

    return _card(
      ok: ok,
      title: 'Certificate',
      headline: ok
          ? '${certificate['common_name'] ?? 'The certificate'} is good for '
                '$days more ${days == 1 ? 'day' : 'days'}, '
                'until ${certificate['expires_at'] ?? 'its stated date'}.'
          : (certificate['problem'] as String? ??
                'The certificate could not be read.'),
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
        if (!ok && verified)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              // The restore worked; where the file lives is the remaining
              // problem. Saying "backup failed" here would be wrong, and saying
              // nothing would be worse.
              'The dump restores cleanly, from '
              '${backups['restored_from'] ?? 'the latest file'}. '
              '${backups['offsite'] == true ? '' : 'It is the local copy only — '
                        'a lost instance takes the backup with it.'}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
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
                // Without this the Send button below is frozen at whatever
                // `_address.text.isEmpty` was when build last ran — a controller
                // notifies the field it drives, never an ancestor State — so the
                // button could never become enabled and the whole test-send
                // feature was inert.
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  labelText: 'Send a test to',
                  isDense: true,
                  border: OutlineInputBorder(),
                ),
              ),
            ),
            const SizedBox(width: 8),
            FilledButton.tonal(
              onPressed: _sending || _address.text.trim().isEmpty
                  ? null
                  : _sendTest,
              child: Text(_sending ? 'Sending…' : 'Send'),
            ),
          ],
        ),
        if (_testResult != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _testResult!,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
      ],
    );
  }

  /// The apps' own error reports, grouped into problems, newest first.
  Widget _appIssues(Map<String, dynamic> issues) {
    final problems = (issues['problems'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final days = issues['days'] ?? 7;
    final total = problems.fold<int>(0, (n, p) => n + ((p['total'] as num?)?.toInt() ?? 0));

    return _card(
      key: const Key('health-app-issues'),
      ok: problems.isEmpty,
      title: 'App issues',
      headline: problems.isEmpty
          ? 'No errors, crashes or freezes reported in the last $days days.'
          : '${problems.length} problems, $total reports in the last $days days. '
              'Newest first; tap one for where it happened.',
      children: [
        for (final p in problems.take(25))
          ExpansionTile(
            key: ValueKey('health-issue-${p['fingerprint']}'),
            tilePadding: EdgeInsets.zero,
            title: Text('${_kindLabel(p['kind'] as String?)} · ${p['message']}',
                maxLines: 2, overflow: TextOverflow.ellipsis),
            subtitle: Text([
              '${p['total']}× by ${p['people']} ${p['people'] == 1 ? 'person' : 'people'}',
              if ((p['modules'] as List?)?.isNotEmpty ?? false) 'in ${(p['modules'] as List).join(', ')}',
              if ((p['platforms'] as List?)?.isNotEmpty ?? false) (p['platforms'] as List).join(', '),
              if ((p['versions'] as List?)?.isNotEmpty ?? false) (p['versions'] as List).join(', '),
              'last ${p['last_seen_at']}',
            ].join(' · ')),
            children: [
              if (p['detail'] != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: SelectableText(p['detail'] as String,
                      style: Theme.of(context).textTheme.bodySmall),
                ),
            ],
          ),
      ],
    );
  }

  static String _kindLabel(String? kind) => switch (kind) {
        'dart_error' => 'Error',
        'native_crash' => 'Crash',
        'freeze' => 'Freeze',
        'api_failure' => 'Failed call',
        _ => 'Issue',
      };

  Widget _outbox(Map<String, dynamic> outbox) {
    final ok = outbox['ok'] == true;
    final oldest = outbox['oldest_minutes'];
    final failed = outbox['failed_jobs'] as int;

    return _card(
      ok: ok && failed == 0,
      title: 'Automation outbox',
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
