import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/side_panel.dart';
import '../email/message_html.dart';

/// What `GET /newsletters/{id}/preflight` said about the open newsletter.
///
/// Every count here is the server's. A Mailchimp list's eligible count is
/// `unknown` because EzBizi cannot see which of its members are subscribed;
/// the screen says so rather than showing the member total as recipients.
class NewsletterReadiness {
  const NewsletterReadiness({
    this.checks = const [],
    this.revision,
    this.testCurrent = false,
    this.eligible,
    this.eligibleState = 'unknown',
    this.listId,
    this.audienceName,
    this.audienceKind,
    this.resolvedAt,
    this.account,
    this.fromName,
    this.fromAddress,
    this.timezone,
  });

  final List<Map<String, dynamic>> checks;
  final int? revision;
  final bool testCurrent;
  final int? eligible;

  /// `known`, `unknown` or `error`.
  final String eligibleState;
  final String? listId;
  final String? audienceName;
  final String? audienceKind;
  final String? resolvedAt;
  final String? account;
  final String? fromName;
  final String? fromAddress;
  final String? timezone;

  List<Map<String, dynamic>> get failed => [
    for (final c in checks)
      if (c['ok'] != true) c,
  ];

  static const error = NewsletterReadiness(eligibleState: 'error');

  factory NewsletterReadiness.fromJson(Map<String, dynamic> data) {
    final audience = data['audience_detail'];
    final sender = data['sender'];
    String? s(Object? v) => v is String && v.trim().isNotEmpty ? v : null;

    return NewsletterReadiness(
      checks: [
        for (final c in (data['checks'] as List? ?? const []))
          if (c is Map) Map<String, dynamic>.from(c),
      ],
      revision: (data['revision'] as num?)?.toInt(),
      testCurrent: data['test_current'] == true,
      eligible: audience is Map ? (audience['eligible'] as num?)?.toInt() : null,
      eligibleState: audience is Map
          ? (audience['eligible_state'] as String? ?? 'unknown')
          : 'unknown',
      listId: audience is Map ? s(audience['list_id']) : null,
      audienceName: audience is Map ? s(audience['name']) : null,
      audienceKind: audience is Map ? s(audience['kind']) : null,
      resolvedAt: audience is Map ? s(audience['resolved_at']) : null,
      account: sender is Map ? s(sender['account']) : null,
      fromName: sender is Map ? s(sender['from_name']) : null,
      fromAddress: sender is Map ? s(sender['from_address']) : null,
      timezone: s(data['scheduled_timezone']),
    );
  }
}

/// The last test send, as the server recorded it.
class NewsletterLastTest {
  const NewsletterLastTest({
    required this.revision,
    required this.to,
    this.at,
  });

  final int revision;
  final String to;
  final String? at;

  static NewsletterLastTest? fromJson(Object? json) {
    if (json is! Map) return null;
    final revision = (json['revision'] as num?)?.toInt();
    if (revision == null) return null;
    return NewsletterLastTest(
      revision: revision,
      to: json['to'] as String? ?? '',
      at: json['at'] as String?,
    );
  }

  /// Never "delivered": Mailchimp taking the request is all EzBizi knows.
  String get sentence => 'Accepted by Mailchimp for $to · revision $revision';
}

/// Preview & test: the server's browser preview at desktop or phone width,
/// and one test email to one typed address, bound to the saved revision.
Future<void> showNewsletterPreviewAndTest(
  BuildContext context, {
  required ApiClient api,
  required String newsletterId,
  required Future<void> Function() onSaveNow,
  required int Function() revision,
  required NewsletterLastTest? lastTest,
  required String? mayTest,
  required ValueChanged<NewsletterLastTest> onTested,
}) {
  return showSidePanel<void>(
    context,
    builder: (context) => SidePanel(
      title: 'Preview & test',
      width: 720,
      child: NewsletterPreviewAndTest(
        api: api,
        newsletterId: newsletterId,
        onSaveNow: onSaveNow,
        revision: revision,
        lastTest: lastTest,
        mayTest: mayTest,
        onTested: onTested,
      ),
    ),
  );
}

class NewsletterPreviewAndTest extends StatefulWidget {
  const NewsletterPreviewAndTest({
    super.key,
    required this.api,
    required this.newsletterId,
    required this.onSaveNow,
    required this.revision,
    required this.lastTest,
    required this.mayTest,
    required this.onTested,
  });

  final ApiClient api;
  final String newsletterId;
  final Future<void> Function() onSaveNow;
  final int Function() revision;
  final NewsletterLastTest? lastTest;

  /// Null when a test can be sent, else why not (permission).
  final String? mayTest;
  final ValueChanged<NewsletterLastTest> onTested;

  @override
  State<NewsletterPreviewAndTest> createState() =>
      _NewsletterPreviewAndTestState();
}

class _NewsletterPreviewAndTestState extends State<NewsletterPreviewAndTest> {
  final TextEditingController _email = TextEditingController();
  bool _phone = false;
  String? _html;
  int? _previewRevision;
  NewsletterLastTest? _last;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _last = widget.lastTest;
    _loadPreview();
  }

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _loadPreview() async {
    await widget.onSaveNow();
    final response = await widget.api.get(
      '/newsletters/${widget.newsletterId}/preview',
    );
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    setState(() {
      _html = data is Map ? (data['html'] as String? ?? '') : '';
      _previewRevision = data is Map ? (data['revision'] as num?)?.toInt() : null;
      if (!response.ok) _error = response.reason;
    });
  }

  Future<void> _sendTest() async {
    final to = _email.text.trim();
    if (to.isEmpty) {
      setState(() => _error = 'Type the address the test should go to.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    await widget.onSaveNow();
    final response = await widget.api.post(
      '/newsletters/${widget.newsletterId}/test',
      {'email': to, 'revision': widget.revision()},
    );
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    final last = data is Map
        ? NewsletterLastTest.fromJson(data['last_test']) ??
              NewsletterLastTest(revision: widget.revision(), to: to)
        : null;
    setState(() {
      _busy = false;
      if (last != null) {
        _last = last;
      } else {
        _error = response.statusCode == 409
            ? 'This newsletter changed elsewhere. Reload it before testing.'
            : response.reason;
      }
    });
    if (last != null) widget.onTested(last);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final last = _last;
    final current = widget.revision();

    return Column(
      key: const ValueKey('newsletter-preview-test-panel'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            SegmentedButton<bool>(
              segments: const [
                ButtonSegment(
                  value: false,
                  label: Text('Desktop'),
                  icon: Icon(Icons.desktop_windows_outlined, size: 16),
                ),
                ButtonSegment(
                  value: true,
                  label: Text('Mobile'),
                  icon: Icon(Icons.phone_iphone, size: 16),
                ),
              ],
              selected: {_phone},
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() => _phone = s.first),
            ),
            Text(
              'Browser preview — not an inbox test'
              '${_previewRevision == null ? '' : ' · revision $_previewRevision'}',
              key: const ValueKey('newsletter-preview-label'),
              style: theme.textTheme.labelMedium,
            ),
          ],
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 460,
          child: _html == null
              ? const Center(child: CircularProgressIndicator())
              : Align(
                  alignment: Alignment.topCenter,
                  child: SizedBox(
                    width: _phone ? 375 : double.infinity,
                    child: MessageHtmlView(html: _html!, ownHtml: true),
                  ),
                ),
        ),
        const SizedBox(height: 16),
        Text('Send a test', style: theme.textTheme.titleSmall),
        const SizedBox(height: 6),
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
        if (last != null) ...[
          const SizedBox(height: 8),
          Text(
            last.sentence,
            key: const ValueKey('newsletter-test-result'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.primary,
            ),
          ),
          if (last.revision != current)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Stale — content changed since this test.',
                key: const ValueKey('newsletter-test-stale'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
        ],
        if (_error != null) ...[
          const SizedBox(height: 6),
          Text(
            _error!,
            key: const ValueKey('newsletter-test-error'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
      ],
    );
  }
}
