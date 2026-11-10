import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/dashboard_card.dart';

/// Checks before a send, and one test email to one typed address.
///
/// Audit 2026-11-10, item 6. The checks come from the server
/// (`GET /newsletters/{id}/preflight`), which knows the footer, the From name
/// and the Mailchimp list; the test goes through Mailchimp's own test path and
/// never reaches the list. [onSaveNow] runs first, so the test is of what is
/// on screen rather than the last autosave.
class NewsletterPreflight extends StatefulWidget {
  const NewsletterPreflight({
    super.key,
    required this.api,
    required this.newsletterId,
    required this.onSaveNow,
    required this.mayTest,
  });

  final ApiClient api;
  final String newsletterId;
  final Future<void> Function() onSaveNow;

  /// Null when a test can be sent, else why not (permission).
  final String? mayTest;

  @override
  State<NewsletterPreflight> createState() => _NewsletterPreflightState();
}

class _NewsletterPreflightState extends State<NewsletterPreflight> {
  final TextEditingController _email = TextEditingController();
  List<Map<String, dynamic>>? _checks;
  String? _result;
  bool _resultOk = false;
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _run() async {
    setState(() {
      _busy = true;
      _result = null;
    });
    await widget.onSaveNow();
    final response = await widget.api.get(
      '/newsletters/${widget.newsletterId}/preflight',
    );
    if (!mounted) return;
    final data = response.data;
    setState(() {
      _busy = false;
      _checks = response.ok && data is Map
          ? [
              for (final c in (data['checks'] as List? ?? const []))
                if (c is Map) Map<String, dynamic>.from(c),
            ]
          : null;
      if (!response.ok) {
        _result = response.reason;
        _resultOk = false;
      }
    });
  }

  Future<void> _sendTest() async {
    final to = _email.text.trim();
    if (to.isEmpty) {
      setState(() {
        _result = 'Type the address the test should go to.';
        _resultOk = false;
      });
      return;
    }
    await _run();
    if (!mounted || _checks == null) return;
    if (_checks!.any((c) => c['ok'] != true)) {
      setState(() {
        _result = 'Fix the checks marked above before sending a test.';
        _resultOk = false;
      });
      return;
    }
    setState(() => _busy = true);
    final response = await widget.api.post(
      '/newsletters/${widget.newsletterId}/test',
      {'email': to},
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      _resultOk = response.ok;
      _result = response.ok
          ? 'Test sent to $to. Only that address gets it.'
          : response.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final checks = _checks;

    return Column(
      key: const ValueKey('newsletter-preflight'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton.icon(
            key: const ValueKey('newsletter-preflight-run'),
            onPressed: _busy ? null : _run,
            icon: const Icon(Icons.fact_check_outlined, size: 16),
            label: const Text('Check before sending'),
          ),
        ),
        if (checks != null)
          for (final c in checks)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    c['ok'] == true
                        ? Icons.check_circle_outline
                        : Icons.error_outline,
                    size: 16,
                    color: c['ok'] == true
                        ? theme.colorScheme.primary
                        : theme.colorScheme.error,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      '${c['label']}: ${c['detail']}',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                ],
              ),
            ),
        const SizedBox(height: 10),
        if (widget.mayTest != null)
          DashboardNote(widget.mayTest!)
        else ...[
          TextField(
            key: const ValueKey('newsletter-test-email'),
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: 'Send one test to',
              hintText: 'you@example.com',
              isDense: true,
            ),
          ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              key: const ValueKey('newsletter-test-send'),
              onPressed: _busy ? null : _sendTest,
              icon: const Icon(Icons.outgoing_mail, size: 16),
              label: const Text('Send test'),
            ),
          ),
        ],
        if (_result != null) ...[
          const SizedBox(height: 6),
          Text(
            _result!,
            key: const ValueKey('newsletter-test-result'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: _resultOk
                  ? theme.colorScheme.primary
                  : theme.colorScheme.error,
            ),
          ),
        ],
      ],
    );
  }
}
