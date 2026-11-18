import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/side_panel.dart';
import '../email/message_html.dart';
import 'newsletter_blocks.dart';

/// Version history (P1a, 2026-11-17): every saved revision with who saved it
/// and when, a preview of the one picked, and Restore. A restore is a NEW
/// revision on the server; the version it replaces stays in the list.
///
/// Returns the restored newsletter as the server answered it, or null.
Future<Map<String, dynamic>?> showNewsletterVersions(
  BuildContext context, {
  required ApiClient api,
  required String newsletterId,
  required int revision,
  required bool canRestore,
}) => showSidePanel<Map<String, dynamic>>(
  context,
  builder: (_) => _Versions(
    api: api,
    id: newsletterId,
    revision: revision,
    canRestore: canRestore,
  ),
);

class _Versions extends StatefulWidget {
  const _Versions({
    required this.api,
    required this.id,
    required this.revision,
    required this.canRestore,
  });

  final ApiClient api;
  final String id;
  final int revision;
  final bool canRestore;

  @override
  State<_Versions> createState() => _VersionsState();
}

class _VersionsState extends State<_Versions> {
  List<Map<String, dynamic>>? _rows;
  int? _picked;
  String? _html;
  String? _error;
  bool _restoring = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/newsletters/${widget.id}/revisions');
    if (!mounted) return;
    setState(() {
      _rows = response.ok && response.data is List
          ? (response.data as List).whereType<Map<String, dynamic>>().toList()
          : const [];
      if (!response.ok) _error = response.reason;
    });
  }

  Future<void> _pick(int revision) async {
    setState(() {
      _picked = revision;
      _html = null;
    });
    final response = await widget.api.get(
      '/newsletters/${widget.id}/revisions/$revision',
    );
    if (!mounted || _picked != revision) return;
    final data = response.ok ? response.data : null;
    setState(() {
      _html = data is Map ? '${data['html'] ?? ''}' : '';
      if (!response.ok) _error = response.reason;
    });
  }

  Future<void> _restore() async {
    final picked = _picked;
    if (picked == null) return;
    setState(() => _restoring = true);
    final response = await widget.api.post(
      '/newsletters/${widget.id}/revisions/$picked/restore',
      {'revision': widget.revision},
    );
    if (!mounted) return;
    if (!response.ok || response.data is! Map) {
      setState(() {
        _restoring = false;
        _error = response.reason;
      });
      return;
    }
    Navigator.of(context).pop(Map<String, dynamic>.from(response.data as Map));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;
    final picked = _picked;
    final current = picked == widget.revision;

    return SidePanel(
      title: 'Version history',
      subtitle: 'Every saved version. Restoring one saves it as a new version, '
          'so nothing newer is lost.',
      width: 720,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Close'),
        ),
        const SizedBox(width: 8),
        if (widget.canRestore)
          FilledButton(
            key: const ValueKey('newsletter-version-restore'),
            onPressed: picked == null || current || _restoring ? null : _restore,
            child: Text(
              picked == null
                  ? 'Pick a version'
                  : current
                  ? 'This is the current version'
                  : 'Restore revision $picked',
            ),
          ),
      ],
      child: Column(
        key: const ValueKey('newsletter-versions'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            DashboardNote(_error!),
            const SizedBox(height: 8),
          ],
          if (rows == null)
            const LinearProgressIndicator()
          else if (rows.isEmpty)
            const DashboardNote('No saved versions yet. Each save adds one.')
          else
            for (final r in rows)
              ListTile(
                key: ValueKey('newsletter-version-${r['revision']}'),
                dense: true,
                selected: r['revision'] == picked,
                leading: Icon(
                  r['restore_point'] == true
                      ? Icons.bookmark_outline
                      : Icons.history,
                  size: 18,
                ),
                title: Text(
                  'Revision ${r['revision']}'
                  '${r['current'] == true ? ' · current' : ''}',
                ),
                subtitle: Text(
                  [
                    '${r['summary'] ?? ''}',
                    [
                      if (r['author'] != null) '${r['author']}',
                      if (r['at'] != null)
                        _when(DateTime.tryParse('${r['at']}')),
                    ].join(' · '),
                  ].where((s) => s.isNotEmpty).join('\n'),
                ),
                onTap: () => _pick((r['revision'] as num).toInt()),
              ),
          if (picked != null) ...[
            const SizedBox(height: 12),
            Text('Revision $picked', style: theme.textTheme.titleSmall),
            const SizedBox(height: 6),
            SizedBox(
              height: 420,
              child: _html == null
                  ? const Center(child: CircularProgressIndicator())
                  : MessageHtmlView(html: _html!, ownHtml: true),
            ),
          ],
        ],
      ),
    );
  }

  static String _when(DateTime? at) {
    if (at == null) return '';
    final l = at.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${l.year}-${two(l.month)}-${two(l.day)} ${two(l.hour)}:${two(l.minute)}';
  }
}

/// The server's saved version beside this device's unsaved copy, after a
/// save was refused because the newsletter changed elsewhere (409).
Future<void> showNewsletterCompare(
  BuildContext context, {
  required ApiClient api,
  required String newsletterId,
  required List<Map<String, dynamic>> localBlocks,
  required String? localHtml,
  required String company,
}) => showSidePanel<void>(
  context,
  builder: (panel) => _Compare(
    api: api,
    id: newsletterId,
    blocks: localBlocks,
    html: localHtml,
    company: company,
  ),
);

class _Compare extends StatefulWidget {
  const _Compare({
    required this.api,
    required this.id,
    required this.blocks,
    required this.html,
    required this.company,
  });

  final ApiClient api;
  final String id;
  final List<Map<String, dynamic>> blocks;
  final String? html;
  final String company;

  @override
  State<_Compare> createState() => _CompareState();
}

class _CompareState extends State<_Compare> {
  String? _server;
  int? _revision;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/newsletters/${widget.id}/preview');
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    setState(() {
      _server = data is Map ? '${data['html'] ?? ''}' : '';
      _revision = data is Map ? (data['revision'] as num?)?.toInt() : null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final local = widget.html != null && widget.html!.trim().isNotEmpty
        ? MessageHtmlView(html: widget.html!, ownHtml: true)
        : SingleChildScrollView(
            child: NewsletterEmail(blocks: widget.blocks, company: widget.company),
          );

    Widget side(String title, Widget body) => Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: theme.textTheme.titleSmall),
          const SizedBox(height: 6),
          SizedBox(height: 520, child: body),
        ],
      ),
    );

    return SidePanel(
      title: 'Compare versions',
      subtitle: 'Nothing is merged. Reload keeps the saved version; Save as copy '
          'keeps yours as a new draft.',
      width: 1000,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Close'),
        ),
      ],
      child: Row(
        key: const ValueKey('newsletter-compare'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          side(
            'Saved on the server${_revision == null ? '' : ' · revision $_revision'}',
            _server == null
                ? const Center(child: CircularProgressIndicator())
                : MessageHtmlView(html: _server!, ownHtml: true),
          ),
          const SizedBox(width: 12),
          side('Your local copy · not saved', local),
        ],
      ),
    );
  }
}
